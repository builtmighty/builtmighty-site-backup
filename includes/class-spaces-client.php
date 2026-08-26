<?php
/**
 * DigitalOcean Spaces client — S3-compatible wrapper for uploads, listing, and deletion.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Aws\Exception\AwsException;
use Aws\Exception\MultipartUploadException;

class Mighty_Backup_Spaces_Client {

    private S3Client $client;
    private string $bucket;
    private string $client_path;

    public function __construct( Mighty_Backup_Settings $settings ) {
        $this->bucket      = $settings->get( 'spaces_bucket' );
        $this->client_path = rtrim( $settings->get( 'client_path' ), '/' );

        $this->client = new S3Client( [
            'version'                 => 'latest',
            'region'                  => 'us-east-1',
            'endpoint'                => 'https://' . $settings->get( 'spaces_endpoint' ),
            'use_path_style_endpoint' => false,
            // Credentials, region, and endpoint are all supplied explicitly below, so the
            // SDK never needs the shared AWS config/credentials files. Disabling the lookup
            // prevents the SDK from probing "$HOME/.aws/config", which triggers an
            // open_basedir warning on hosts (e.g. Sevalla) where HOME is outside the
            // allowed paths. See VAL-892.
            'use_aws_shared_config_files' => false,
            'credentials'             => [
                'key'    => $settings->get( 'spaces_access_key' ),
                'secret' => $settings->get_secret_key(),
            ],
        ] );
    }

    /**
     * Test the connection by listing up to 1 object.
     *
     * @return string Success message with bucket info.
     * @throws \Exception On connection failure.
     */
    public function test_connection(): string {
        try {
            $result = $this->client->listObjectsV2( [
                'Bucket'  => $this->bucket,
                'Prefix'  => $this->client_path . '/',
                'MaxKeys' => 1,
            ] );
        } catch ( AwsException $e ) {
            throw new \Exception(
                sprintf( 'Connection failed [%s]: %s', $e->getAwsErrorCode(), $e->getAwsErrorMessage() )
            );
        }

        $count = $result['KeyCount'] ?? 0;
        return sprintf(
            'Connected to bucket "%s" at path "%s" (%d existing objects found).',
            $this->bucket,
            $this->client_path,
            $count
        );
    }

    /**
     * Upload a local file to Spaces using multipart upload.
     *
     * @param string $local_path  Absolute path to the local file.
     * @param string $remote_key  Key within the client path (e.g., "databases/backup-2026-02-18-030000.sql.gz").
     * @return string The full remote key.
     * @throws \Exception On upload failure after retries.
     */
    public function upload( string $local_path, string $remote_key ): string {
        $full_key    = $this->client_path . '/' . ltrim( $remote_key, '/' );
        $max_retries = (int) apply_filters( 'mighty_backup_upload_max_retries', 3 );
        $part_size   = max( 5 * 1024 * 1024, (int) apply_filters( 'mighty_backup_upload_part_size', 25 * 1024 * 1024 ) );
        $concurrency = (int) apply_filters( 'mighty_backup_upload_concurrency', 5 );

        Mighty_Backup_Log_Stream::add( 'Starting multipart upload: ' . $remote_key );

        // Total part count is filesize / part_size, rounded up. Used for the
        // live progress indicator + ETA.
        $file_size   = @filesize( $local_path );
        $total_parts = $file_size > 0 ? max( 1, (int) ceil( $file_size / $part_size ) ) : 0;

        // Counter is shared with the before_upload closure so progress survives
        // multipart retries (we re-create the uploader with the prior state).
        $completed_parts = 0;
        $upload_started  = microtime( true );

        $progress_callback = function () use ( &$completed_parts, $total_parts, $upload_started, $remote_key ) {
            gc_collect_cycles();

            if ( $total_parts <= 0 ) {
                return;
            }

            // before_upload fires BEFORE each part. So when it fires the Nth
            // time, parts 1..(N-1) are done and we're starting part N.
            $current = $completed_parts + 1;
            $elapsed = microtime( true ) - $upload_started;
            $eta     = null;
            if ( $completed_parts > 0 ) {
                $per_part = $elapsed / $completed_parts;
                $eta      = (int) round( $per_part * ( $total_parts - $completed_parts ) );
            }

            Mighty_Backup_Log_Stream::set_progress(
                sprintf( 'Uploading %s: part %d of %d', basename( $remote_key ), $current, $total_parts ),
                $current,
                $total_parts,
                $eta
            );
            ++$completed_parts;
        };

        $uploader = new MultipartUploader( $this->client, $local_path, [
            'bucket'        => $this->bucket,
            'key'           => $full_key,
            'part_size'     => $part_size,
            'concurrency'   => $concurrency,
            'before_upload' => $progress_callback,
        ] );

        $attempt = 0;
        do {
            $attempt++;
            try {
                $uploader->upload();
                Mighty_Backup_Log_Stream::clear_progress();
                Mighty_Backup_Log_Stream::add( 'Upload complete: ' . $remote_key );
                return $full_key;
            } catch ( MultipartUploadException $e ) {
                if ( $attempt >= $max_retries ) {
                    Mighty_Backup_Log_Stream::clear_progress();
                    // Best-effort abort so the parts already uploaded don't
                    // sit on Spaces forever consuming storage. The bucket's
                    // AbortIncompleteMultipartUpload lifecycle rule (Days: 1)
                    // and the daily sweep_orphan_multiparts cron are the
                    // belt-and-suspenders backstops if this fails.
                    $this->abort_upload_state( $e );
                    throw new \Exception(
                        sprintf( 'Upload failed after %d attempts: %s', $max_retries, $e->getMessage() )
                    );
                }
                Mighty_Backup_Log_Stream::add( 'Upload attempt ' . $attempt . ' failed, retrying...' );
                // Resume from where we left off — preserve progress callback
                // so the part counter keeps ticking on retry.
                $uploader = new MultipartUploader( $this->client, $local_path, [
                    'state'         => $e->getState(),
                    'before_upload' => $progress_callback,
                ] );
            } catch ( \Throwable $other ) {
                // Non-multipart errors (e.g. AwsException from a single PutObject
                // fast-path for small files, or network errors raised outside
                // the SDK retry layer). No multipart state to abort, but
                // still need to clear the progress indicator.
                Mighty_Backup_Log_Stream::clear_progress();
                throw $other;
            }
        } while ( $attempt < $max_retries );

        Mighty_Backup_Log_Stream::clear_progress();
        return $full_key;
    }

    /**
     * Best-effort abort of a multipart upload whose state we hold via a
     * MultipartUploadException. The state exposes Bucket / Key / UploadId
     * via getId(). Failures here are non-fatal — the bucket lifecycle rule
     * installed at activation will eventually clean up if this miss.
     */
    private function abort_upload_state( MultipartUploadException $e ): void {
        try {
            $id = $e->getState()->getId();
            if ( empty( $id['UploadId'] ) || empty( $id['Bucket'] ) || empty( $id['Key'] ) ) {
                return;
            }
            $this->client->abortMultipartUpload( [
                'Bucket'   => $id['Bucket'],
                'Key'      => $id['Key'],
                'UploadId' => $id['UploadId'],
            ] );
            Mighty_Backup_Log_Stream::add( sprintf(
                'Aborted incomplete multipart upload %s (UploadId %s)',
                $id['Key'],
                substr( (string) $id['UploadId'], 0, 12 ) . '…'
            ) );
        } catch ( \Throwable $abort_err ) {
            error_log( 'Mighty Backup: abortMultipartUpload failed — ' . $abort_err->getMessage() );
        }
    }

    /**
     * Idempotently install a bucket lifecycle rule that aborts incomplete
     * multipart uploads after 1 day. Defense-in-depth: even if our explicit
     * abort_upload_state misses (worker SIGKILL between attempt-fail and
     * abort), Spaces will eventually reclaim the orphan storage.
     *
     * The rule scopes to our `client_path/` prefix so a shared bucket
     * isn't affected. Existing rules on the bucket are preserved; we only
     * replace any prior version of our own rule (matched by ID).
     *
     * Best-effort: a failure (credentials lacking PutBucketLifecycleConfiguration,
     * bucket not supporting lifecycle) is logged but doesn't throw — the
     * sweep_orphan_multiparts daily cron is the second backstop.
     */
    public function ensure_lifecycle_policy(): void {
        $rule_id = 'mighty-backup-abort-incomplete-multiparts';

        try {
            $rules = [];
            try {
                $current = $this->client->getBucketLifecycleConfiguration( [ 'Bucket' => $this->bucket ] );
                $rules   = $current['Rules'] ?? [];
            } catch ( AwsException $e ) {
                // NoSuchLifecycleConfiguration is expected on a fresh bucket;
                // start with an empty rule set. Re-throw anything else.
                if ( $e->getAwsErrorCode() !== 'NoSuchLifecycleConfiguration' ) {
                    throw $e;
                }
            }

            // Remove any prior version of our rule (idempotency).
            $rules = array_values( array_filter( $rules, static function ( $r ) use ( $rule_id ) {
                return ( $r['ID'] ?? '' ) !== $rule_id;
            } ) );

            $rules[] = [
                'ID'                              => $rule_id,
                'Status'                          => 'Enabled',
                'Filter'                          => [ 'Prefix' => $this->client_path . '/' ],
                'AbortIncompleteMultipartUpload'  => [ 'DaysAfterInitiation' => 1 ],
            ];

            $this->client->putBucketLifecycleConfiguration( [
                'Bucket'                 => $this->bucket,
                'LifecycleConfiguration' => [ 'Rules' => $rules ],
            ] );
        } catch ( \Throwable $e ) {
            error_log( 'Mighty Backup: ensure_lifecycle_policy failed — ' . $e->getMessage() );
        }
    }

    /**
     * Find and abort multipart uploads older than $max_age_hours under our
     * client_path prefix. Called from the daily retention cron as a backstop
     * for failures of both per-upload abort and the bucket lifecycle rule.
     *
     * @param int         $max_age_hours Abort uploads initiated before this.
     * @param string|null $object_stem   When set, only uploads whose filename
     *                                   starts with "<stem>-" are eligible.
     *                                   Required under a multisource prefix,
     *                                   where siblings share client_path and an
     *                                   unscoped sweep would abort another
     *                                   site's in-flight upload.
     * @return array{aborted:int, errors:string[]} aborted = abort count.
     */
    public function sweep_orphan_multiparts( int $max_age_hours = 24, ?string $object_stem = null ): array {
        $cutoff  = time() - max( 1, $max_age_hours ) * 3600;
        $aborted = 0;
        $errors  = [];
        $scope   = ( $object_stem !== null && $object_stem !== '' ) ? $object_stem . '-' : null;

        try {
            $paginator = $this->client->getPaginator( 'ListMultipartUploads', [
                'Bucket' => $this->bucket,
                'Prefix' => $this->client_path . '/',
            ] );

            foreach ( $paginator as $page ) {
                foreach ( $page['Uploads'] ?? [] as $upload ) {
                    if ( $scope !== null && ! str_starts_with( basename( (string) ( $upload['Key'] ?? '' ) ), $scope ) ) {
                        continue; // Belongs to a sibling site sharing this prefix.
                    }

                    $initiated = $upload['Initiated'] ?? null;
                    if ( $initiated instanceof \DateTimeInterface ) {
                        $ts = $initiated->getTimestamp();
                    } else {
                        $ts = is_string( $initiated ) ? strtotime( $initiated ) : false;
                    }
                    if ( $ts === false || $ts > $cutoff ) {
                        continue;
                    }
                    try {
                        $this->client->abortMultipartUpload( [
                            'Bucket'   => $this->bucket,
                            'Key'      => $upload['Key'],
                            'UploadId' => $upload['UploadId'],
                        ] );
                        $aborted++;
                    } catch ( \Throwable $err ) {
                        $errors[] = ( $upload['Key'] ?? '?' ) . ': ' . $err->getMessage();
                    }
                }
            }
        } catch ( \Throwable $e ) {
            $errors[] = 'ListMultipartUploads: ' . $e->getMessage();
        }

        return [ 'aborted' => $aborted, 'errors' => $errors ];
    }

    /**
     * List objects under a prefix, sorted by LastModified descending.
     *
     * @param string $prefix Sub-path within client path (e.g., "databases/").
     * @return array Array of objects with Key, Size, LastModified.
     */
    public function list_objects( string $prefix ): array {
        $full_prefix = $this->client_path . '/' . ltrim( $prefix, '/' );
        $objects     = [];

        $paginator = $this->client->getPaginator( 'ListObjectsV2', [
            'Bucket' => $this->bucket,
            'Prefix' => $full_prefix,
        ] );

        foreach ( $paginator as $page ) {
            foreach ( $page['Contents'] ?? [] as $object ) {
                $objects[] = [
                    'Key'          => $object['Key'],
                    'Size'         => $object['Size'],
                    'LastModified' => $object['LastModified']->format( 'Y-m-d H:i:s' ),
                ];
            }
        }

        // Sort newest first.
        usort( $objects, function ( $a, $b ) {
            return strcmp( $b['LastModified'], $a['LastModified'] );
        } );

        return $objects;
    }

    /**
     * Delete objects by their keys.
     *
     * @param array $keys Array of full S3 keys to delete.
     */
    public function delete_objects( array $keys ): void {
        if ( empty( $keys ) ) {
            return;
        }

        $delete_objects = array_map( function ( $key ) {
            return [ 'Key' => $key ];
        }, $keys );

        // DeleteObjects accepts max 1000 per request.
        foreach ( array_chunk( $delete_objects, 1000 ) as $chunk ) {
            $this->client->deleteObjects( [
                'Bucket' => $this->bucket,
                'Delete' => [
                    'Objects' => $chunk,
                    'Quiet'   => true,
                ],
            ] );
        }
    }

    /**
     * Generate a temporary pre-signed download URL for an object.
     *
     * @param string $key    Full S3 key of the object.
     * @param int    $expiry URL lifetime in seconds (default 1 hour).
     * @return string Pre-signed URL.
     */
    public function get_presigned_url( string $key, int $expiry = 3600 ): string {
        $cmd = $this->client->getCommand( 'GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ] );

        $request = $this->client->createPresignedRequest( $cmd, "+{$expiry} seconds" );

        return (string) $request->getUri();
    }

    /**
     * Get the full client path prefix.
     */
    public function get_client_path(): string {
        return $this->client_path;
    }
}
