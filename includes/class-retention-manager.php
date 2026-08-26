<?php
/**
 * Retention manager — prunes old backups from DO Spaces based on configured limits.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Retention_Manager {

    private Mighty_Backup_Spaces_Client $client;
    private int $retention_count;
    private string $object_stem;

    /**
     * @param string $object_stem The object-name stem this site writes (see
     *                            Mighty_Backup_Settings::get_object_stem()).
     *                            Defaults to the historical 'backup'.
     */
    public function __construct(
        Mighty_Backup_Spaces_Client $client,
        int $retention_count,
        string $object_stem = Mighty_Backup_Settings::DEFAULT_OBJECT_STEM
    ) {
        $this->client          = $client;
        $this->retention_count = max( 1, $retention_count );
        $this->object_stem     = $object_stem !== '' ? $object_stem : Mighty_Backup_Settings::DEFAULT_OBJECT_STEM;
    }

    /**
     * Prune old backups beyond the retention limit.
     *
     * Listings are scoped to `<type>/<stem>-`, not just `<type>/`. That matters
     * for two reasons under a multisource prefix, where sibling sites share one
     * client_path:
     *
     *   1. Safety. prune_prefix() sorts by full key, which in a shared listing
     *      sorts by site name before timestamp — so an unscoped prune would keep
     *      N objects belonging to the alphabetically-last site and queue every
     *      other site's entire history for deletion.
     *   2. Legacy objects. Pre-multisource `backup-*` keys fall outside the
     *      scoped prefix, so they are never listed and never deleted.
     *
     * With the default stem the prefix is `databases/backup-`, which every
     * object written before this change already matches — so single-source
     * behaviour is unchanged.
     *
     * @return array Summary of what was deleted.
     */
    public function prune(): array {
        $db_deleted    = 0;
        $files_deleted = 0;

        try {
            $db_deleted = $this->prune_prefix( 'databases/' . $this->object_stem . '-' );
        } catch ( \Exception $e ) {
            Mighty_Backup_Log_Stream::add( 'Retention cleanup failed for databases: ' . $e->getMessage() );
        }

        try {
            $files_deleted = $this->prune_prefix( 'files/' . $this->object_stem . '-' );
        } catch ( \Exception $e ) {
            Mighty_Backup_Log_Stream::add( 'Retention cleanup failed for files: ' . $e->getMessage() );
        }

        if ( $this->object_stem !== Mighty_Backup_Settings::DEFAULT_OBJECT_STEM ) {
            Mighty_Backup_Log_Stream::add( sprintf(
                'Retention scoped to "%s-*" objects. Pre-multisource "backup-*" objects are outside retention and are never deleted — prune them by hand if they are no longer needed.',
                $this->object_stem
            ) );
        }

        return [
            'databases_deleted' => $db_deleted,
            'files_deleted'     => $files_deleted,
        ];
    }

    /**
     * Prune objects under a specific prefix.
     *
     * @param string $prefix Sub-path including the stem
     *                       (e.g., "databases/backup-" or "files/acme-store-").
     * @return int Number of objects deleted.
     */
    private function prune_prefix( string $prefix ): int {
        $objects = $this->client->list_objects( $prefix );

        if ( empty( $objects ) ) {
            // Zero objects under a configured prefix usually means client_path
            // was renamed (or the multisource site name changed) and old uploads
            // now live under a forgotten prefix, which will never get pruned.
            // Surface this in the live log so operators can spot the bill-leak
            // before it grows.
            Mighty_Backup_Log_Stream::add( sprintf(
                'Retention: no objects under %s — verify the client_path and site-name settings are current (a rename orphans the old prefix).',
                $prefix
            ) );
            return 0;
        }

        // Sort by key descending. Because the prefix pins everything up to and
        // including the stem, the remainder of the key starts with the backup
        // timestamp ({Y-m-d-His}.{sql,tar}.gz), which is lexicographically
        // monotonic — unlike S3's LastModified, which can shift under uploader
        // clock skew or re-uploads of the same key.
        usort( $objects, static function ( $a, $b ) {
            return strcmp( (string) ( $b['Key'] ?? '' ), (string) ( $a['Key'] ?? '' ) );
        } );

        if ( count( $objects ) <= $this->retention_count ) {
            return 0;
        }

        // Keep the first N (newest by key-embedded timestamp), delete the rest.
        // Additional safety: refuse to delete anything LastModified within the
        // last hour — even if key-sort thinks it's stale, a freshly-uploaded
        // object shouldn't be reaped (catches edge cases like a key whose
        // timestamp portion was hand-edited or clock-skewed at upload).
        $to_delete = array_slice( $objects, $this->retention_count );
        $cutoff    = time() - 3600;
        $keys      = [];
        foreach ( $to_delete as $obj ) {
            $lm_ts = isset( $obj['LastModified'] ) ? strtotime( (string) $obj['LastModified'] ) : false;
            if ( $lm_ts !== false && $lm_ts >= $cutoff ) {
                Mighty_Backup_Log_Stream::add( sprintf(
                    'Retention: refusing to delete %s — uploaded < 1h ago (clock skew?)',
                    $obj['Key'] ?? '?'
                ) );
                continue;
            }
            $keys[] = $obj['Key'];
        }

        if ( empty( $keys ) ) {
            return 0;
        }

        $this->client->delete_objects( $keys );

        return count( $keys );
    }
}
