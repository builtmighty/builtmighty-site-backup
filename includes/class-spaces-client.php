<?php
/**
 * DigitalOcean Spaces client — S3-compatible wrapper for uploads, listing, and deletion.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;

class BM_Backup_Spaces_Client {

    private S3Client $client;
    private string $bucket;
    private string $client_path;

    public function __construct( BM_Backup_Settings $settings ) {
        $this->bucket      = $settings->get( 'spaces_bucket' );
        $this->client_path = rtrim( $settings->get( 'client_path' ), '/' );

        $this->client = new S3Client( [
            'version'                 => 'latest',
            'region'                  => 'us-east-1',
            'endpoint'                => 'https://' . $settings->get( 'spaces_endpoint' ),
            'use_path_style_endpoint' => false,
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
        $result = $this->client->listObjectsV2( [
            'Bucket'  => $this->bucket,
            'Prefix'  => $this->client_path . '/',
            'MaxKeys' => 1,
        ] );

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
        $max_retries = 3;

        $uploader = new MultipartUploader( $this->client, $local_path, [
            'bucket'      => $this->bucket,
            'key'         => $full_key,
            'part_size'   => 10 * 1024 * 1024, // 10 MB
            'concurrency' => 5,
            'before_upload' => function () {
                gc_collect_cycles();
            },
        ] );

        $attempt = 0;
        do {
            $attempt++;
            try {
                $uploader->upload();
                return $full_key;
            } catch ( MultipartUploadException $e ) {
                if ( $attempt >= $max_retries ) {
                    throw new \Exception(
                        sprintf( 'Upload failed after %d attempts: %s', $max_retries, $e->getMessage() )
                    );
                }
                // Resume from where we left off.
                $uploader = new MultipartUploader( $this->client, $local_path, [
                    'state' => $e->getState(),
                ] );
            }
        } while ( $attempt < $max_retries );

        return $full_key;
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
     * Get the full client path prefix.
     */
    public function get_client_path(): string {
        return $this->client_path;
    }
}
