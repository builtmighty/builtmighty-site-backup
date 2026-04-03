<?php
/**
 * Backup manager — orchestrates backup via Action Scheduler action chain.
 *
 * Flow:
 *   mighty_backup_step_start        -> creates log, initializes state
 *   mighty_backup_step_export_db    -> exports DB to temp .sql.gz
 *   mighty_backup_step_archive_files -> creates tar.gz of file system
 *   mighty_backup_step_upload_db    -> multipart upload DB to Spaces
 *   mighty_backup_step_upload_files -> multipart upload files to Spaces
 *   mighty_backup_step_cleanup      -> retention prune, delete temps, mark complete
 *
 * State is persisted in a site option between actions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Manager {

    private const STATE_OPTION = 'bm_backup_current_state';
    private const ACTION_GROUP = 'mighty-backup';

    /**
     * All steps in order. Steps are skipped based on backup type.
     */
    private const STEPS = [
        'start',
        'export_db',
        'archive_files',
        'upload_db',
        'upload_files',
        'cleanup',
    ];

    /**
     * Human-readable labels for each step.
     */
    private const STEP_LABELS = [
        'start'         => 'Initializing backup',
        'export_db'     => 'Exporting database',
        'archive_files' => 'Archiving files',
        'upload_db'     => 'Uploading database to Spaces',
        'upload_files'  => 'Uploading files to Spaces',
        'cleanup'       => 'Cleaning up',
    ];

    /**
     * Register Action Scheduler hooks.
     */
    public function init(): void {
        add_action( 'mighty_backup_step_start', [ $this, 'step_start' ] );
        add_action( 'mighty_backup_step_export_db', [ $this, 'step_export_db' ] );
        add_action( 'mighty_backup_step_archive_files', [ $this, 'step_archive_files' ] );
        add_action( 'mighty_backup_step_upload_db', [ $this, 'step_upload_db' ] );
        add_action( 'mighty_backup_step_upload_files', [ $this, 'step_upload_files' ] );
        add_action( 'mighty_backup_step_cleanup', [ $this, 'step_cleanup' ] );
    }

    /**
     * Schedule a backup. Returns immediately — work happens in the background.
     *
     * @param string $type    'full', 'db', or 'files'.
     * @param string $trigger 'scheduled', 'manual', or 'cli'.
     * @return array State array with backup_id.
     * @throws \Exception If a backup is already running or deps are missing.
     */
    public function schedule( string $type = 'full', string $trigger = 'scheduled' ): array {
        if ( ! mighty_backup_has_sdk() ) {
            throw new \Exception( 'AWS SDK not installed. Run "composer install" in the plugin directory.' );
        }

        if ( ! mighty_backup_has_action_scheduler() ) {
            throw new \Exception( 'Action Scheduler not available.' );
        }

        $settings = new Mighty_Backup_Settings();
        if ( ! $settings->is_configured() ) {
            throw new \Exception( 'Plugin not configured. Please save your DO Spaces credentials.' );
        }

        if ( $this->is_running() ) {
            throw new \Exception( 'A backup is already in progress.' );
        }

        // Check available disk space against the last backup's size.
        $this->check_disk_space( $type );

        $timestamp = gmdate( 'Y-m-d-His' );

        // Build the list of steps to execute based on type.
        $steps = $this->get_steps_for_type( $type );

        $state = [
            'type'             => $type,
            'trigger'          => $trigger,
            'timestamp'        => $timestamp,
            'log_id'           => null,
            'status'           => 'pending',
            'current_step'     => $steps[0],
            'steps'            => $steps,
            'step_index'       => 0,
            'db_local_path'    => $this->get_temp_path( "bm-backup-{$timestamp}.sql.gz" ),
            'files_local_path' => $this->get_temp_path( "bm-backup-{$timestamp}.tar.gz" ),
            'db_file_size'     => null,
            'files_file_size'  => null,
            'db_remote_key'    => null,
            'files_remote_key' => null,
            'error'            => null,
            'started_at'       => current_time( 'mysql', true ),
        ];

        // Clear any leftover state from a previous backup so the first poll
        // doesn't pick up stale completed/failed status.
        delete_site_option( self::STATE_OPTION );

        $this->save_state( $state );

        // Schedule the first step to run immediately.
        as_schedule_single_action( time(), 'mighty_backup_step_start', [], self::ACTION_GROUP );

        return $state;
    }

    /**
     * Step: Start — create log entry, advance to next step.
     */
    public function step_start(): void {
        $state = $this->get_state();
        if ( ! $state ) {
            return;
        }

        $this->set_time_limit();

        do_action( 'mighty_backup_before_start', $state );

        $logger = new Mighty_Backup_Logger();
        $log_id = $logger->start( $state['type'], $state['trigger'] );

        $state['log_id'] = $log_id;
        $state['status'] = 'running';
        $this->save_state( $state );

        Mighty_Backup_Log_Stream::start();
        Mighty_Backup_Log_Stream::add( 'Backup started (' . $state['type'] . ' / ' . $state['trigger'] . ')' );

        do_action( 'mighty_backup_after_start', $state );

        $this->advance( $state );
    }

    /**
     * Step: Export database to gzipped SQL file.
     *
     * For mysqldump-based exports, runs in a single action (fast, low memory).
     * For PHP-based exports, splits work across multiple actions by re-scheduling
     * itself until all tables are exported, then compresses and advances.
     */
    public function step_export_db(): void {
        $state = $this->get_state();
        if ( ! $state ) {
            return;
        }

        $this->set_time_limit();
        $this->update_current_step( $state, 'export_db' );

        $settings    = new Mighty_Backup_Settings();
        $streamlined = (bool) $settings->get( 'streamlined_mode', false );
        $exporter    = new Mighty_Backup_Database_Exporter( $streamlined );

        try {
            // First invocation: determine method and initialize.
            if ( ! isset( $state['db_export'] ) ) {
                $method = $exporter->get_export_method();

                // mysqldump paths run in a single action — no chunking needed.
                if ( in_array( $method, [ 'mysqldump', 'streamlined_hybrid' ], true ) ) {
                    do_action( 'mighty_backup_before_export_db', $state );

                    Mighty_Backup_Log_Stream::add(
                        'Exporting database' . ( $streamlined ? ' (streamlined mode)' : '' ) . '...'
                    );

                    $size = $exporter->export( $state['db_local_path'] );

                    $state['db_file_size'] = $size;
                    $this->save_state( $state );

                    Mighty_Backup_Log_Stream::add( 'Database export complete (' . size_format( $size ) . ')' );
                    do_action( 'mighty_backup_after_export_db', $state, $state['db_local_path'] );

                    $this->advance( $state );
                    return;
                }

                // PHP path: initialize chunked export.
                do_action( 'mighty_backup_before_export_db', $state );

                $tables   = $exporter->get_table_list();
                $raw_path = $state['db_local_path'] . '.raw.sql';

                $state['db_export'] = [
                    'tables'            => $tables,
                    'tables_exported'   => 0,
                    'raw_path'          => $raw_path,
                    'streamlined_config' => $streamlined ? $exporter->get_streamlined_config() : null,
                ];
                $this->save_state( $state );

                Mighty_Backup_Log_Stream::add(
                    'Exporting database via PHP'
                    . ( $streamlined ? ' (streamlined)' : '' )
                    . ' — ' . count( $tables ) . ' tables, chunked export'
                );
            }

            // Process the next chunk of tables.
            $db             = $state['db_export'];
            $chunk_seconds  = (int) apply_filters( 'mighty_backup_db_chunk_seconds', 30 );
            $is_first_chunk = ( $db['tables_exported'] === 0 );

            $next_index = $exporter->export_tables_chunk(
                $db['raw_path'],
                $db['tables'],
                $db['tables_exported'],
                $chunk_seconds,
                $is_first_chunk,
                $db['streamlined_config']
            );

            $state['db_export']['tables_exported'] = $next_index;
            $this->save_state( $state );

            $total = count( $db['tables'] );
            Mighty_Backup_Log_Stream::add( "Exported {$next_index}/{$total} tables" );

            if ( $next_index < $total ) {
                // More tables remain — re-schedule this same step.
                Mighty_Backup_Log_Stream::flush();
                as_schedule_single_action( time(), 'mighty_backup_step_export_db', [], self::ACTION_GROUP );
                return;
            }

            // All tables done — finalize (compress to .sql.gz).
            Mighty_Backup_Log_Stream::add( 'Compressing database export...' );
            $size = $exporter->finalize_export( $db['raw_path'], $state['db_local_path'] );

            $state['db_file_size'] = $size;
            unset( $state['db_export'] ); // Clean up transient sub-state.
            $this->save_state( $state );

            Mighty_Backup_Log_Stream::add( 'Database export complete (' . size_format( $size ) . ')' );
            do_action( 'mighty_backup_after_export_db', $state, $state['db_local_path'] );

            $this->advance( $state );

        } catch ( \Exception $e ) {
            // Clean up raw temp file on failure.
            if ( isset( $state['db_export']['raw_path'] ) && file_exists( $state['db_export']['raw_path'] ) ) {
                @unlink( $state['db_export']['raw_path'] );
            }
            $this->fail( $state, 'Database export failed: ' . $e->getMessage() );
        }
    }

    /**
     * Step: Archive files to tar.gz.
     */
    public function step_archive_files(): void {
        $state = $this->get_state();
        if ( ! $state ) {
            return;
        }

        $this->set_time_limit();
        $this->update_current_step( $state, 'archive_files' );

        do_action( 'mighty_backup_before_archive_files', $state );

        try {
            Mighty_Backup_Log_Stream::add( 'Archiving files...' );

            $settings = new Mighty_Backup_Settings();
            $archiver = new Mighty_Backup_File_Archiver( $settings );
            $size     = $archiver->archive( $state['files_local_path'] );

            $state['files_file_size'] = $size;
            $this->save_state( $state );

            Mighty_Backup_Log_Stream::add( 'File archive complete (' . size_format( $size ) . ')' );

            do_action( 'mighty_backup_after_archive_files', $state, $state['files_local_path'] );

            $this->advance( $state );

        } catch ( \Exception $e ) {
            $this->fail( $state, 'File archive failed: ' . $e->getMessage() );
        }
    }

    /**
     * Step: Upload database backup to DO Spaces.
     */
    public function step_upload_db(): void {
        $state = $this->get_state();
        if ( ! $state ) {
            return;
        }

        $this->set_time_limit();
        $this->update_current_step( $state, 'upload_db' );

        do_action( 'mighty_backup_before_upload', $state, 'db' );

        try {
            $db_size_str = $state['db_file_size'] ? size_format( $state['db_file_size'] ) : 'unknown size';
            Mighty_Backup_Log_Stream::add( 'Uploading database (' . $db_size_str . ')...' );

            $settings = new Mighty_Backup_Settings();
            $client   = new Mighty_Backup_Spaces_Client( $settings );

            $remote_key = $client->upload(
                $state['db_local_path'],
                "databases/backup-{$state['timestamp']}.sql.gz"
            );

            $state['db_remote_key'] = $remote_key;
            $this->save_state( $state );

            Mighty_Backup_Log_Stream::add( 'Database uploaded to Spaces' );

            do_action( 'mighty_backup_after_upload', $state, 'db', $remote_key );

            $this->advance( $state );

        } catch ( \Exception $e ) {
            $this->fail( $state, 'Database upload failed: ' . $e->getMessage() );
        }
    }

    /**
     * Step: Upload files backup to DO Spaces.
     */
    public function step_upload_files(): void {
        $state = $this->get_state();
        if ( ! $state ) {
            return;
        }

        $this->set_time_limit();
        $this->update_current_step( $state, 'upload_files' );

        do_action( 'mighty_backup_before_upload', $state, 'files' );

        try {
            $files_size_str = $state['files_file_size'] ? size_format( $state['files_file_size'] ) : 'unknown size';
            Mighty_Backup_Log_Stream::add( 'Uploading files archive (' . $files_size_str . ')...' );

            $settings = new Mighty_Backup_Settings();
            $client   = new Mighty_Backup_Spaces_Client( $settings );

            $remote_key = $client->upload(
                $state['files_local_path'],
                "files/backup-{$state['timestamp']}.tar.gz"
            );

            $state['files_remote_key'] = $remote_key;
            $this->save_state( $state );

            Mighty_Backup_Log_Stream::add( 'Files archive uploaded to Spaces' );

            do_action( 'mighty_backup_after_upload', $state, 'files', $remote_key );

            $this->advance( $state );

        } catch ( \Exception $e ) {
            $this->fail( $state, 'Files upload failed: ' . $e->getMessage() );
        }
    }

    /**
     * Step: Cleanup — retention prune, delete temp files, mark complete.
     */
    public function step_cleanup(): void {
        $state = $this->get_state();
        if ( ! $state ) {
            return;
        }

        $this->set_time_limit();
        $this->update_current_step( $state, 'cleanup' );

        Mighty_Backup_Log_Stream::add( 'Running retention cleanup...' );

        try {
            // Retention cleanup.
            $settings        = new Mighty_Backup_Settings();
            $client          = new Mighty_Backup_Spaces_Client( $settings );
            $retention_count = (int) $settings->get( 'retention_count', 7 );
            $retention       = new Mighty_Backup_Retention_Manager( $client, $retention_count );
            $retention->prune();

        } catch ( \Exception $e ) {
            // Retention failure is non-critical — log but don't fail the backup.
            error_log( 'Mighty Backup: Retention cleanup failed — ' . $e->getMessage() );
            Mighty_Backup_Log_Stream::add( 'Retention cleanup warning: ' . $e->getMessage() );
        }

        // Delete temp files.
        Mighty_Backup_Log_Stream::add( 'Deleting temporary files...' );
        foreach ( [ $state['db_local_path'], $state['files_local_path'] ] as $path ) {
            if ( $path && file_exists( $path ) ) {
                unlink( $path );
            }
        }

        // Mark log entry as completed.
        $logger = new Mighty_Backup_Logger();
        $logger->complete( $state['log_id'], [
            'db_file_size'     => $state['db_file_size'],
            'files_file_size'  => $state['files_file_size'],
            'db_remote_key'    => $state['db_remote_key'],
            'files_remote_key' => $state['files_remote_key'],
        ] );

        // Mark state as completed.
        $state['status']       = 'completed';
        $state['current_step'] = null;
        $this->save_state( $state );

        Mighty_Backup_Log_Stream::add( 'Backup complete!' );
        Mighty_Backup_Log_Stream::flush();

        do_action( 'mighty_backup_completed', $state );
    }

    /**
     * Advance to the next step in the chain.
     */
    private function advance( array $state ): void {
        Mighty_Backup_Log_Stream::flush();
        $next_index = $state['step_index'] + 1;

        if ( $next_index >= count( $state['steps'] ) ) {
            // All steps done — shouldn't happen since cleanup is always last.
            return;
        }

        $next_step = $state['steps'][ $next_index ];

        $state['step_index']  = $next_index;
        $state['current_step'] = $next_step;
        $this->save_state( $state );

        // Schedule the next step to run immediately.
        as_schedule_single_action( time(), "mighty_backup_step_{$next_step}", [], self::ACTION_GROUP );
    }

    /**
     * Handle a step failure.
     */
    private function fail( array $state, string $error ): void {
        $state['status'] = 'failed';
        $state['error']  = $error;
        $this->save_state( $state );

        Mighty_Backup_Log_Stream::add( 'FAILED: ' . $error );
        Mighty_Backup_Log_Stream::flush();

        do_action( 'mighty_backup_failed', $state, $error );

        // Update log entry.
        if ( $state['log_id'] ) {
            $logger = new Mighty_Backup_Logger();
            $logger->fail( $state['log_id'], $error );
        }

        // Clean up temp files (including raw SQL from chunked export).
        $paths = [ $state['db_local_path'], $state['files_local_path'] ];
        if ( isset( $state['db_export']['raw_path'] ) ) {
            $paths[] = $state['db_export']['raw_path'];
        }
        foreach ( $paths as $path ) {
            if ( $path && file_exists( $path ) ) {
                unlink( $path );
            }
        }

        // Send failure notification.
        $settings = new Mighty_Backup_Settings();
        $this->maybe_send_failure_email( $settings, $error, $state['timestamp'] );
    }

    /**
     * Get the steps to execute for a given backup type.
     */
    private function get_steps_for_type( string $type ): array {
        $steps = [ 'start' ];

        if ( in_array( $type, [ 'full', 'db' ], true ) ) {
            $steps[] = 'export_db';
        }
        if ( in_array( $type, [ 'full', 'files' ], true ) ) {
            $steps[] = 'archive_files';
        }
        if ( in_array( $type, [ 'full', 'db' ], true ) ) {
            $steps[] = 'upload_db';
        }
        if ( in_array( $type, [ 'full', 'files' ], true ) ) {
            $steps[] = 'upload_files';
        }

        $steps[] = 'cleanup';
        return $steps;
    }

    /**
     * Check if a backup is currently running.
     */
    public function is_running(): bool {
        $state = $this->get_state();
        return $state && $state['status'] === 'running';
    }

    /**
     * Get the current backup state.
     *
     * @return array|null State array, or null if no backup in progress.
     */
    public function get_state(): ?array {
        $state = get_site_option( self::STATE_OPTION );
        return is_array( $state ) ? $state : null;
    }

    /**
     * Get the current status for the admin UI.
     */
    public function get_status( int $log_since = 0 ): array {
        $state    = $this->get_state();
        $log_data = Mighty_Backup_Log_Stream::get( $log_since );

        if ( ! $state ) {
            return [
                'active'      => false,
                'status'      => 'idle',
                'message'     => 'No backup in progress.',
                'log_entries' => $log_data['entries'],
                'log_index'   => $log_data['index'],
            ];
        }

        $step_label = self::STEP_LABELS[ $state['current_step'] ] ?? $state['current_step'];
        $total      = count( $state['steps'] );
        $current    = $state['step_index'] + 1;

        // Sub-progress within the chunked DB export phase.
        $sub_progress = null;
        if ( $state['current_step'] === 'export_db' && isset( $state['db_export'] ) ) {
            $db           = $state['db_export'];
            $total_tables = count( $db['tables'] );
            $exported     = $db['tables_exported'];
            $step_label   = sprintf( 'Exporting database (%d/%d tables)', $exported, $total_tables );
            $sub_progress = $total_tables > 0 ? round( ( $exported / $total_tables ) * 100 ) : 0;
        }

        // Interpolate sub-progress so the progress bar moves smoothly within a step.
        if ( $sub_progress !== null ) {
            $step_start = round( ( ( $current - 1 ) / $total ) * 100 );
            $step_end   = round( ( $current / $total ) * 100 );
            $progress   = $step_start + (int) round( ( $sub_progress / 100 ) * ( $step_end - $step_start ) );
        } else {
            $progress = round( ( $current / $total ) * 100 );
        }

        return [
            'active'       => in_array( $state['status'], [ 'pending', 'running' ], true ),
            'status'       => $state['status'],
            'type'         => $state['type'],
            'trigger'      => $state['trigger'],
            'timestamp'    => $state['timestamp'],
            'current_step' => $state['current_step'],
            'step_label'   => $step_label,
            'step_number'  => $current,
            'total_steps'  => $total,
            'progress'     => $progress,
            'error'        => $state['error'],
            'db_file_size'    => $state['db_file_size'],
            'files_file_size' => $state['files_file_size'],
            'started_at'   => $state['started_at'],
            'message'      => match ( $state['status'] ) {
                'running'   => sprintf( 'Step %d/%d: %s...', $current, $total, $step_label ),
                'pending'   => 'Backup pending — waiting for background processing...',
                'completed' => 'Backup completed.',
                'failed'    => 'Backup failed.',
                default     => '',
            },
            'log_entries'  => $log_data['entries'],
            'log_index'    => $log_data['index'],
        ];
    }

    /**
     * Cancel a running or pending backup.
     *
     * Unschedules all pending Action Scheduler actions for the backup, deletes
     * temp files, marks the log entry as failed, and clears state.
     *
     * @return bool False if no backup was in progress.
     */
    public function cancel(): bool {
        $state = $this->get_state();
        if ( ! $state || ! in_array( $state['status'], [ 'pending', 'running' ], true ) ) {
            return false;
        }

        // Unschedule any queued backup step actions.
        foreach ( self::STEPS as $step ) {
            as_unschedule_all_actions( "mighty_backup_step_{$step}", [], self::ACTION_GROUP );
        }

        // Delete temp files (including raw SQL from chunked export).
        $paths = [ $state['db_local_path'], $state['files_local_path'] ];
        if ( isset( $state['db_export']['raw_path'] ) ) {
            $paths[] = $state['db_export']['raw_path'];
        }
        foreach ( $paths as $path ) {
            if ( $path && file_exists( $path ) ) {
                unlink( $path );
            }
        }

        // Mark log entry as failed.
        if ( $state['log_id'] ) {
            $logger = new Mighty_Backup_Logger();
            $logger->fail( $state['log_id'], 'Cancelled.' );
        }

        $this->clear_state();
        Mighty_Backup_Log_Stream::clear();

        return true;
    }

    /**
     * Clear the backup state (after viewing results or for reset).
     */
    public function clear_state(): void {
        delete_site_option( self::STATE_OPTION );
    }

    /**
     * Save the backup state.
     */
    private function save_state( array $state ): void {
        update_site_option( self::STATE_OPTION, $state );
    }

    /**
     * Update the current step in state.
     */
    private function update_current_step( array &$state, string $step ): void {
        $state['current_step'] = $step;
        $this->save_state( $state );
    }

    /**
     * Check that the temp directory has enough free space for the backup.
     * Estimates based on the last completed backup's total size.
     * Skips the check on the first-ever backup (no estimate available).
     *
     * @throws \Exception If disk space is insufficient.
     */
    private function check_disk_space( string $type ): void {
        $logger = new Mighty_Backup_Logger();
        $last   = $logger->get_last_completed();

        if ( ! $last ) {
            return; // First backup — no estimate available.
        }

        $estimated = 0;
        if ( in_array( $type, [ 'full', 'db' ], true ) ) {
            $estimated += (int) ( $last['db_file_size'] ?? 0 );
        }
        if ( in_array( $type, [ 'full', 'files' ], true ) ) {
            $estimated += (int) ( $last['files_file_size'] ?? 0 );
        }

        if ( $estimated <= 0 ) {
            return;
        }

        $temp_dir   = get_temp_dir();
        $free_space = @disk_free_space( $temp_dir );

        if ( $free_space === false ) {
            return; // Can't determine — skip gracefully.
        }

        $required = (int) ( $estimated * 1.2 ); // 20% safety margin.

        if ( $free_space < $required ) {
            throw new \Exception( sprintf(
                'Insufficient disk space. Available: %s, estimated need: %s. Free up space in the temp directory or reduce backup scope.',
                size_format( $free_space ),
                size_format( $required )
            ) );
        }
    }

    /**
     * Get a temporary file path with restrictive permissions.
     */
    private function get_temp_path( string $filename ): string {
        $path = get_temp_dir() . $filename;
        if ( ! file_exists( $path ) ) {
            touch( $path );
            chmod( $path, 0600 );
        }
        return $path;
    }

    /**
     * Remove PHP time limit for long-running steps.
     */
    private function set_time_limit(): void {
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 0 );
        }
    }

    /**
     * Send failure notification email if configured.
     */
    private function maybe_send_failure_email( Mighty_Backup_Settings $settings, string $error, string $timestamp ): void {
        if ( ! $settings->get( 'notify_on_failure' ) ) {
            return;
        }

        $email = $settings->get( 'notification_email' );
        if ( empty( $email ) ) {
            $email = get_site_option( 'admin_email' );
        }

        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf( '[%s] Backup failed — %s', $site_name, $timestamp );
        $message   = sprintf(
            "A backup failed on %s.\n\nTimestamp: %s\nError: %s\n\nPlease check the backup logs in wp-admin for details.",
            home_url(),
            $timestamp,
            $error
        );

        wp_mail( $email, $subject, $message );
    }
}
