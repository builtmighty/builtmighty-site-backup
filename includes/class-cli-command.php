<?php
/**
 * WP-CLI commands for Mighty Backup.
 *
 * Usage:
 *   wp mighty-backup run [--type=<type>] [--async]
 *   wp mighty-backup status
 *   wp mighty-backup cancel
 *   wp mighty-backup list [--type=<type>]
 *   wp mighty-backup prune
 *   wp mighty-backup test
 *   wp mighty-backup dev-mode [--disable]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_CLI_Command {

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
     * [--timeout=<seconds>]
     * : Maximum seconds to wait for backup completion (default: 21600 = 6 hours).
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup run
     *     wp mighty-backup run --type=db
     *     wp mighty-backup run --async
     *     wp mighty-backup run --timeout=3600
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function run( $args, $assoc_args ) {
        $type    = $assoc_args['type'] ?? 'full';
        $async   = isset( $assoc_args['async'] );
        $timeout = (int) ( $assoc_args['timeout'] ?? 21600 );

        try {
            $manager = new Mighty_Backup_Manager();
            $manager->schedule( $type, 'cli' );
            WP_CLI::log( "Backup scheduled ({$type})." );

            if ( $async ) {
                WP_CLI::success( 'Backup is running in the background. Use "wp mighty-backup status" to check progress.' );
                return;
            }

            // Poll until complete.
            WP_CLI::log( 'Waiting for backup to complete...' );

            $last_step  = '';
            $start_time = time();
            while ( true ) {
                if ( ( time() - $start_time ) > $timeout ) {
                    WP_CLI::error( "Backup timed out after {$timeout} seconds. The backup may still be running in the background." );
                }

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
     *     wp mighty-backup status
     */
    public function status( $args, $assoc_args ) {
        $manager   = new Mighty_Backup_Manager();
        $logger    = new Mighty_Backup_Logger();
        $scheduler = new Mighty_Backup_Scheduler();

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

        // Dev mode.
        if ( Mighty_Backup_Dev_Mode::is_dev_mode() ) {
            WP_CLI::warning( 'Dev mode is active — scheduled backups are paused (site URL mismatch).' );
            WP_CLI::log( sprintf( '  Live URL:    %s', Mighty_Backup_Dev_Mode::get_live_url() ) );
            WP_CLI::log( sprintf( '  Current URL: %s', network_site_url() ) );
        }

        // Next scheduled run.
        $next = $scheduler->get_next_run();
        if ( $next ) {
            $suffix = Mighty_Backup_Dev_Mode::is_dev_mode() ? ' (paused — dev mode)' : '';
            WP_CLI::log( sprintf( "\nNext scheduled: %s UTC%s", gmdate( 'Y-m-d H:i:s', $next ), $suffix ) );
        } else {
            WP_CLI::log( "\nNo backup scheduled." );
        }
    }

    /**
     * Cancel a running or pending backup.
     *
     * Removes queued backup actions, deletes temporary files, and marks
     * the backup log entry as failed.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup cancel
     */
    public function cancel( $args, $assoc_args ) {
        $manager = new Mighty_Backup_Manager();

        if ( ! $manager->get_state() ) {
            WP_CLI::warning( 'No backup is currently running or pending.' );
            return;
        }

        $cancelled = $manager->cancel();

        if ( $cancelled ) {
            WP_CLI::success( 'Backup cancelled. Temporary files deleted and scheduled actions removed.' );
        } else {
            WP_CLI::warning( 'No active backup to cancel.' );
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
     *     wp mighty-backup list
     *     wp mighty-backup list --type=db
     */
    public function list( $args, $assoc_args ) {
        $type = $assoc_args['type'] ?? 'all';

        try {
            $settings = new Mighty_Backup_Settings();
            $client   = new Mighty_Backup_Spaces_Client( $settings );

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
     *     wp mighty-backup prune
     */
    public function prune( $args, $assoc_args ) {
        try {
            $settings        = new Mighty_Backup_Settings();
            $client          = new Mighty_Backup_Spaces_Client( $settings );
            $retention_count = (int) $settings->get( 'retention_count', 7 );
            $retention       = new Mighty_Backup_Retention_Manager( $client, $retention_count );

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
     *     wp mighty-backup test
     */
    public function test( $args, $assoc_args ) {
        try {
            $settings = new Mighty_Backup_Settings();

            if ( ! $settings->is_configured() ) {
                WP_CLI::error( 'Plugin is not configured. Please enter your DO Spaces credentials in wp-admin.' );
            }

            $client  = new Mighty_Backup_Spaces_Client( $settings );
            $message = $client->test_connection();

            WP_CLI::success( $message );

        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }

    /**
     * Show or change the dev mode status.
     *
     * Dev mode is automatically activated when the site URL changes (e.g.
     * after cloning to staging).  Scheduled backups are paused until dev
     * mode is explicitly disabled.
     *
     * ## OPTIONS
     *
     * [--disable]
     * : Exit dev mode by updating the stored URL to the current site URL
     *   and rescheduling backups.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup dev-mode
     *     wp mighty-backup dev-mode --disable
     *
     * @subcommand dev-mode
     */
    public function dev_mode( $args, $assoc_args ) {
        $live_url    = Mighty_Backup_Dev_Mode::get_live_url();
        $current_url = network_site_url();
        $is_dev      = Mighty_Backup_Dev_Mode::is_dev_mode();

        if ( isset( $assoc_args['disable'] ) ) {
            if ( ! $is_dev ) {
                WP_CLI::success( 'Dev mode is not active — nothing to do.' );
                return;
            }

            update_site_option( Mighty_Backup_Dev_Mode::LIVE_URL_OPTION, $current_url );

            $scheduler = new Mighty_Backup_Scheduler();
            $scheduler->reschedule();

            WP_CLI::success( 'Dev mode disabled. Automatic backups re-enabled.' );
            return;
        }

        // Display status.
        WP_CLI::log( sprintf( 'Dev mode:    %s', $is_dev ? 'ACTIVE' : 'inactive' ) );
        WP_CLI::log( sprintf( 'Live URL:    %s', $live_url ?: '(not set)' ) );
        WP_CLI::log( sprintf( 'Current URL: %s', $current_url ) );

        if ( $is_dev ) {
            WP_CLI::warning( 'Scheduled backups are paused. Run "wp mighty-backup dev-mode --disable" to re-enable.' );
        }
    }
}
