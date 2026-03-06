<?php
/**
 * WP-CLI commands for BM Site Backup.
 *
 * Usage:
 *   wp bm-backup run [--type=<type>] [--async]
 *   wp bm-backup status
 *   wp bm-backup list [--type=<type>]
 *   wp bm-backup prune
 *   wp bm-backup test
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BM_Backup_CLI_Command {

    /**
     * Run a backup.
     *
     * By default, schedules the backup via Action Scheduler and polls until
     * complete. Use --async to schedule and return immediately.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Type of backup to run.
     * ---
     * default: full
     * options:
     *   - full
     *   - db
     *   - files
     * ---
     *
     * [--async]
     * : Schedule the backup and return immediately without waiting.
     *
     * ## EXAMPLES
     *
     *     wp bm-backup run
     *     wp bm-backup run --type=db
     *     wp bm-backup run --async
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function run( $args, $assoc_args ) {
        $type  = $assoc_args['type'] ?? 'full';
        $async = isset( $assoc_args['async'] );

        try {
            $manager = new BM_Backup_Manager();
            $manager->schedule( $type, 'cli' );
            WP_CLI::log( "Backup scheduled ({$type})." );

            if ( $async ) {
                WP_CLI::success( 'Backup is running in the background. Use "wp bm-backup status" to check progress.' );
                return;
            }

            // Poll until complete.
            WP_CLI::log( 'Waiting for backup to complete...' );

            $last_step = '';
            while ( true ) {
                sleep( 2 );

                // Process any pending Action Scheduler actions.
                if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
                    ActionScheduler_QueueRunner::instance()->run();
                }

                $status = $manager->get_status();

                // Show step transitions.
                if ( $status['active'] && isset( $status['message'] ) && $status['message'] !== $last_step ) {
                    WP_CLI::log( '  ' . $status['message'] );
                    $last_step = $status['message'];
                }

                if ( ! $status['active'] ) {
                    break;
                }
            }

            if ( $status['status'] === 'completed' ) {
                if ( ! empty( $status['db_file_size'] ) ) {
                    WP_CLI::log( sprintf( '  Database: %s', size_format( $status['db_file_size'] ) ) );
                }
                if ( ! empty( $status['files_file_size'] ) ) {
                    WP_CLI::log( sprintf( '  Files: %s', size_format( $status['files_file_size'] ) ) );
                }
                $manager->clear_state();
                WP_CLI::success( 'Backup completed successfully.' );
            } else {
                $error = $status['error'] ?? 'Unknown error';
                $manager->clear_state();
                WP_CLI::error( "Backup failed: {$error}" );
            }

        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }

    /**
     * Show the current backup status and next scheduled run.
     *
     * ## EXAMPLES
     *
     *     wp bm-backup status
     */
    public function status( $args, $assoc_args ) {
        $manager   = new BM_Backup_Manager();
        $logger    = new BM_Backup_Logger();
        $scheduler = new BM_Backup_Scheduler();

        // Current backup status.
        $status = $manager->get_status();
        if ( $status['active'] ) {
            WP_CLI::log( 'Backup in progress:' );
            WP_CLI::log( sprintf( '  Status: %s', $status['message'] ) );
            WP_CLI::log( sprintf( '  Progress: %d%%', $status['progress'] ) );
        } else {
            WP_CLI::log( 'No backup currently running.' );
        }

        // Last completed backup.
        $last = $logger->get_last_completed();
        if ( $last ) {
            WP_CLI::log( "\nLast completed backup:" );
            WP_CLI::log( sprintf( '  Date:       %s', $last['completed_at'] ) );
            WP_CLI::log( sprintf( '  Type:       %s', $last['backup_type'] ) );
            WP_CLI::log( sprintf( '  Trigger:    %s', $last['trigger_type'] ) );
            if ( $last['db_file_size'] ) {
                WP_CLI::log( sprintf( '  DB size:    %s', size_format( $last['db_file_size'] ) ) );
            }
            if ( $last['files_file_size'] ) {
                WP_CLI::log( sprintf( '  Files size: %s', size_format( $last['files_file_size'] ) ) );
            }
        } else {
            WP_CLI::log( "\nNo completed backups found." );
        }

        // Next scheduled run.
        $next = $scheduler->get_next_run();
        if ( $next ) {
            WP_CLI::log( sprintf( "\nNext scheduled: %s UTC", gmdate( 'Y-m-d H:i:s', $next ) ) );
        } else {
            WP_CLI::log( "\nNo backup scheduled." );
        }
    }

    /**
     * List backups stored on DigitalOcean Spaces.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Type of backups to list.
     * ---
     * default: all
     * options:
     *   - all
     *   - db
     *   - files
     * ---
     *
     * ## EXAMPLES
     *
     *     wp bm-backup list
     *     wp bm-backup list --type=db
     */
    public function list( $args, $assoc_args ) {
        $type = $assoc_args['type'] ?? 'all';

        try {
            $settings = new BM_Backup_Settings();
            $client   = new BM_Backup_Spaces_Client( $settings );

            $prefixes = [];
            if ( in_array( $type, [ 'all', 'db' ], true ) ) {
                $prefixes['Database backups'] = 'databases/';
            }
            if ( in_array( $type, [ 'all', 'files' ], true ) ) {
                $prefixes['File backups'] = 'files/';
            }

            foreach ( $prefixes as $label => $prefix ) {
                $objects = $client->list_objects( $prefix );

                WP_CLI::log( "\n{$label}:" );
                if ( empty( $objects ) ) {
                    WP_CLI::log( '  (none)' );
                    continue;
                }

                $table_data = array_map( function ( $obj ) {
                    return [
                        'Key'           => basename( $obj['Key'] ),
                        'Size'          => size_format( $obj['Size'] ),
                        'Last Modified' => $obj['LastModified'],
                    ];
                }, $objects );

                WP_CLI\Utils\format_items( 'table', $table_data, [ 'Key', 'Size', 'Last Modified' ] );
            }

        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }

    /**
     * Manually trigger retention cleanup.
     *
     * ## EXAMPLES
     *
     *     wp bm-backup prune
     */
    public function prune( $args, $assoc_args ) {
        try {
            $settings        = new BM_Backup_Settings();
            $client          = new BM_Backup_Spaces_Client( $settings );
            $retention_count = (int) $settings->get( 'retention_count', 7 );
            $retention       = new BM_Backup_Retention_Manager( $client, $retention_count );

            WP_CLI::log( sprintf( 'Running retention cleanup (keeping last %d backups)...', $retention_count ) );

            $result = $retention->prune();

            WP_CLI::success( sprintf(
                'Pruned %d database backup(s) and %d file backup(s).',
                $result['databases_deleted'],
                $result['files_deleted']
            ) );

        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }

    /**
     * Test the DigitalOcean Spaces connection.
     *
     * ## EXAMPLES
     *
     *     wp bm-backup test
     */
    public function test( $args, $assoc_args ) {
        try {
            $settings = new BM_Backup_Settings();

            if ( ! $settings->is_configured() ) {
                WP_CLI::error( 'Plugin is not configured. Please enter your DO Spaces credentials in wp-admin.' );
            }

            $client  = new BM_Backup_Spaces_Client( $settings );
            $message = $client->test_connection();

            WP_CLI::success( $message );

        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }
}
