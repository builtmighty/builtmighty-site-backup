<?php
/**
 * WP-CLI repair commands for Mighty Backup.
 *
 * Usage:
 *   wp mighty-backup repair placeholders [--dry-run] [--no-backup-first]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Repair_CLI_Command {

    /**
     * Repair `{HASH}` wpdb-placeholder corruption persisted in the database.
     *
     * Scans the standard WordPress core tables (options, posts, *meta) for
     * any 64-hex-in-braces token, replaces it with `%`, and recomputes the
     * length prefix of any PHP-serialized strings that contained the token.
     * Updates are issued via raw $wpdb->query() — NOT update_option() /
     * update_post_meta(), which would re-escape `%` and recreate the bug.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Count corrupted rows per table and exit without modifying the database.
     *
     * [--no-backup-first]
     * : Skip the pre-flight database backup. By default the command schedules
     *   a `db` backup via the standard backup manager and aborts if it fails.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup repair placeholders --dry-run
     *     wp mighty-backup repair placeholders
     *     wp mighty-backup repair placeholders --no-backup-first
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Named arguments.
     */
    public function placeholders( $args, $assoc_args ) {
        $dry_run     = isset( $assoc_args['dry-run'] );
        $skip_backup = isset( $assoc_args['no-backup-first'] );

        // ── Detection ────────────────────────────────────────────────────
        $sample = Mighty_Backup_Placeholder_Repair::detect_sample_token();
        if ( $sample === null ) {
            WP_CLI::success( 'No persisted wpdb placeholder tokens detected. Nothing to repair.' );
            return;
        }

        WP_CLI::log( 'Detected persisted placeholder token (sample): ' . $sample );

        // ── Count phase ──────────────────────────────────────────────────
        $counts = Mighty_Backup_Placeholder_Repair::count_corruption();
        $total  = 0;
        $rows   = [];
        foreach ( $counts as $entry ) {
            if ( $entry['count'] === 0 ) {
                continue;
            }
            $rows[] = $entry;
            $total += $entry['count'];
        }

        if ( $total === 0 ) {
            WP_CLI::success( 'Sample token found but no rows match the precise scan pattern. Nothing to repair.' );
            return;
        }

        WP_CLI::log( "Found {$total} corrupted row(s) across " . count( $rows ) . ' table/column pair(s):' );
        if ( ! empty( $rows ) ) {
            WP_CLI\Utils\format_items( 'table', $rows, [ 'table', 'column', 'count' ] );
        }

        if ( $dry_run ) {
            WP_CLI::success( 'Dry run complete. No changes written.' );
            return;
        }

        // ── Pre-flight backup ────────────────────────────────────────────
        if ( ! $skip_backup ) {
            WP_CLI::log( 'Taking a pre-flight database backup before repair...' );
            try {
                $manager = new Mighty_Backup_Manager();
                $manager->schedule( 'db', 'repair' );

                // Drive Action Scheduler synchronously until the backup is done
                // or fails. Mirrors the polling loop in the main `run` command.
                $start_time = time();
                $timeout    = 21600;
                while ( true ) {
                    if ( ( time() - $start_time ) > $timeout ) {
                        WP_CLI::error( 'Pre-flight backup timed out. Aborting repair.' );
                    }
                    sleep( 2 );
                    $manager->process_next_action();
                    $status = $manager->get_status();
                    if ( ! $status['active'] ) {
                        break;
                    }
                }
                if ( ( $status['status'] ?? '' ) !== 'completed' ) {
                    $error = $status['error'] ?? 'unknown error';
                    $manager->clear_state();
                    WP_CLI::error( "Pre-flight backup failed: {$error}. Aborting repair." );
                }
                $manager->clear_state();
                WP_CLI::log( 'Pre-flight backup completed.' );
            } catch ( \Throwable $e ) {
                WP_CLI::error( 'Pre-flight backup error: ' . $e->getMessage() );
            }
        } else {
            WP_CLI::warning( '--no-backup-first specified; skipping pre-flight backup. Make sure you have a recent backup.' );
        }

        // ── Repair phase ─────────────────────────────────────────────────
        WP_CLI::log( 'Repairing rows...' );
        $result = Mighty_Backup_Placeholder_Repair::repair_all();

        if ( ! empty( $result['errors'] ) ) {
            foreach ( $result['errors'] as $err ) {
                WP_CLI::warning( $err );
            }
        }

        WP_CLI::log( sprintf( 'Updated %d row(s).', $result['rows_updated'] ) );

        // ── Verify ───────────────────────────────────────────────────────
        $post_counts = Mighty_Backup_Placeholder_Repair::count_corruption();
        $remaining   = 0;
        foreach ( $post_counts as $entry ) {
            $remaining += $entry['count'];
        }

        if ( $remaining > 0 ) {
            WP_CLI::warning( sprintf(
                '%d corrupted row(s) still remain after repair. Some rows may have failed to update; check logs.',
                $remaining
            ) );
        }

        // ── Cache flush ──────────────────────────────────────────────────
        WP_CLI::log( 'Flushing caches...' );
        Mighty_Backup_Placeholder_Repair::flush_caches();

        if ( $remaining === 0 ) {
            WP_CLI::success( sprintf( 'Repair complete. %d row(s) updated, 0 remaining.', $result['rows_updated'] ) );
        } else {
            WP_CLI::error( sprintf(
                'Repair finished with %d remaining corrupted row(s). Review warnings above.',
                $remaining
            ) );
        }
    }
}
