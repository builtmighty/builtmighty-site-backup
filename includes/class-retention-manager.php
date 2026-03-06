<?php
/**
 * Retention manager — prunes old backups from DO Spaces based on configured limits.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BM_Backup_Retention_Manager {

    private BM_Backup_Spaces_Client $client;
    private int $retention_count;

    public function __construct( BM_Backup_Spaces_Client $client, int $retention_count ) {
        $this->client          = $client;
        $this->retention_count = max( 1, $retention_count );
    }

    /**
     * Prune old backups beyond the retention limit.
     *
     * @return array Summary of what was deleted.
     */
    public function prune(): array {
        $db_deleted    = $this->prune_prefix( 'databases/' );
        $files_deleted = $this->prune_prefix( 'files/' );

        return [
            'databases_deleted' => $db_deleted,
            'files_deleted'     => $files_deleted,
        ];
    }

    /**
     * Prune objects under a specific prefix.
     *
     * @param string $prefix Sub-path (e.g., "databases/" or "files/").
     * @return int Number of objects deleted.
     */
    private function prune_prefix( string $prefix ): int {
        $objects = $this->client->list_objects( $prefix );

        // Already sorted newest first by list_objects().
        if ( count( $objects ) <= $this->retention_count ) {
            return 0;
        }

        // Keep the first N, delete the rest.
        $to_delete = array_slice( $objects, $this->retention_count );
        $keys      = array_column( $to_delete, 'Key' );

        $this->client->delete_objects( $keys );

        return count( $keys );
    }
}
