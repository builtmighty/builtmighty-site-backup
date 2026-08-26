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

        // Friendly early-out for the common case (operator clicks "Run Now"
        // while a backup is already running). The atomic claim below is the
        // race-safe gate; this check just produces a clearer error message.
        if ( $this->is_running() ) {
            throw new \Exception( 'A backup is already in progress.' );
        }

        // Check available disk space against the last backup's size.
        $this->check_disk_space( $type );

        $timestamp = gmdate( 'Y-m-d-His' );

        // Build the list of steps to execute based on type.
        $steps = $this->get_steps_for_type( $type );

        // Resolve the object-name stem once, up front. Reading it per upload
        // step would let a mid-backup settings change split the DB and files
        // archives across two naming conventions.
        $object_stem = ( new Mighty_Backup_Settings() )->get_object_stem();

        $state = [
            'type'             => $type,
            'trigger'          => $trigger,
            'timestamp'        => $timestamp,
            'object_stem'      => $object_stem,
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

        // Atomic claim via add_site_option as a CAS primitive. add_site_option
        // returns false if the key already exists, giving us a single-winner
        // race even if wp-cron, admin "Run Now", and `wp mighty-backup run`
        // all reach this point simultaneously.
        if ( ! add_site_option( self::STATE_OPTION, $state ) ) {
            // The option exists. If it's stale (completed/failed), wipe it
            // and retry the claim. If a racing process beat us to a fresh
            // claim, the retry will lose and we throw.
            $current = $this->get_state();
            if ( is_array( $current ) && in_array( $current['status'] ?? '', [ 'pending', 'running', 'cancelling' ], true ) ) {
                throw new \Exception( 'A backup just started in another request — please retry.' );
            }
            delete_site_option( self::STATE_OPTION );
            if ( ! add_site_option( self::STATE_OPTION, $state ) ) {
                throw new \Exception( 'A backup just started in another request — please retry.' );
            }
        }

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

        // Idempotency: AS can re-fire a step after a worker SIGKILL/OOM
        // between action completion and claim release. If log_id is already
        // set, this step has already run successfully — just advance.
        if ( ! empty( $state['log_id'] ) ) {
            $this->advance( $state );
            return;
        }

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

        // Idempotency: if the export already produced a .sql.gz and the
        // chunked sub-state is gone, this is an AS re-fire after a worker
        // death between save and dispatch. Advance without redumping.
        // (Chunked paths keep db_export populated until finalization, so
        // mid-progress re-fires correctly resume via the existing logic.)
        if ( ! empty( $state['db_file_size'] ) && ! isset( $state['db_export'] ) ) {
            $this->advance( $state );
            return;
        }

        // State-shape validator: a backup in flight at upgrade time, or a
        // hand-edited STATE_OPTION, may carry a db_export sub-state in the
        // wrong shape for the current code (e.g., 2.13.0 chunked-mysqldump
        // shape vs older PHP-only shape). Fail with a clear message instead
        // of dereferencing missing keys deep in the run loop. The 029bc0b
        // hot-fix only patched get_status(); this is the missing twin.
        if ( isset( $state['db_export'] ) && ! $this->validate_db_export_shape( $state['db_export'] ) ) {
            $this->fail( $state, 'Database export state is in an unexpected shape (likely a stale backup from a prior plugin version). Cancel and start a fresh backup.' );
            return;
        }

        $settings              = new Mighty_Backup_Settings();
        $streamlined           = (bool) $settings->get( 'streamlined_mode', false );
        $excluded_tables       = (array) $settings->get( 'excluded_tables', [] );
        $structure_only_tables = (array) $settings->get( 'structure_only_tables', [] );
        $exporter              = new Mighty_Backup_Database_Exporter(
            $streamlined,
            $excluded_tables,
            $structure_only_tables
        );

        try {
            // First invocation: determine method and initialize.
            if ( ! isset( $state['db_export'] ) ) {
                $method = $exporter->get_export_method();

                if ( in_array( $method, [ 'mysqldump', 'streamlined_hybrid' ], true ) ) {
                    // Decide between single-shot (no big tables), chunked
                    // mysqldump (big tables + non-streamlined), or fall-through
                    // to PHP path (streamlined + big tables — shell mysqldump
                    // can't replicate PHP-side row filtering).
                    $all_sizes       = $settings->get_tables_with_size();
                    $threshold_bytes = (int) apply_filters(
                        'mighty_backup_large_table_threshold_bytes',
                        (int) $settings->get( 'db_large_table_threshold_mb', 1024 ) * 1024 * 1024
                    );
                    $big_tables              = $exporter->get_large_tables( $all_sizes, $threshold_bytes );
                    $needs_streamlined_php   = $streamlined && ! empty( $big_tables );

                    if ( $needs_streamlined_php ) {
                        Mighty_Backup_Log_Stream::add( sprintf(
                            'Streamlined mode + %d large table(s) — using chunked PHP exporter for correct filtering',
                            count( $big_tables )
                        ) );
                        // Drop through to PHP path init below.
                    } elseif ( empty( $big_tables ) ) {
                        // Single-shot mysqldump / streamlined_hybrid — fits in one action.
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
                    } else {
                        // Chunked mysqldump path — at least one table exceeds the
                        // threshold and streamlined isn't in play. Dump small
                        // tables in one invocation, append schema-only for big
                        // tables, then range-dump big tables across subsequent
                        // Action Scheduler chunks.
                        do_action( 'mighty_backup_before_export_db', $state );

                        $raw_path = $state['db_local_path'] . '.raw.sql';
                        $big_list = array_keys( $big_tables );

                        $sizes_str = implode( ', ', array_map(
                            fn( $t ) => $t . ' (' . size_format( $big_tables[ $t ] ) . ')',
                            $big_list
                        ) );
                        Mighty_Backup_Log_Stream::add(
                            'Exporting database via chunked mysqldump — large tables: ' . $sizes_str
                        );

                        $exporter->write_chunked_mysqldump_preamble( $raw_path );
                        $exporter->dump_small_tables_via_mysqldump(
                            $raw_path,
                            $big_list,
                            $structure_only_tables
                        );

                        // Schema for every big table goes in upfront — the range
                        // loop only emits INSERTs from this point on.
                        foreach ( $big_list as $bt ) {
                            $exporter->dump_table_schema_only_via_mysqldump( $raw_path, $bt );
                        }

                        // Resolve PK info for the FIRST big table so the next chunk
                        // can start range-dumping immediately. Tables without a
                        // single-column PK are skipped (schema-only, with a warning).
                        $first_table_state = $this->init_big_table_state(
                            $exporter, $raw_path, $big_list, 0
                        );

                        $state['db_export'] = [
                            'method'                    => 'mysqldump_chunked',
                            'raw_path'                  => $raw_path,
                            'big_tables'                => $big_list,
                            'big_tables_index'          => $first_table_state['index'],
                            'current_table_mode'        => $first_table_state['mode'],
                            'current_table_pk'          => $first_table_state['pk'],
                            'current_table_numeric'     => $first_table_state['numeric'],
                            'current_table_source'      => $first_table_state['source'],
                            'current_table_max_pk'      => $first_table_state['max_pk'],
                            'current_table_last_pk'     => $first_table_state['last_pk'],
                            'current_table_range_size'  => $first_table_state['range_size'],
                            'table_sizes'               => array_intersect_key( $all_sizes, array_flip( $big_list ) ),
                        ];
                        $this->save_state( $state );
                        Mighty_Backup_Log_Stream::flush();
                        as_schedule_single_action( time(), 'mighty_backup_step_export_db', [], self::ACTION_GROUP );
                        return;
                    }
                }

                // PHP path: initialize chunked export.
                do_action( 'mighty_backup_before_export_db', $state );

                $tables   = $exporter->get_table_list();
                $raw_path = $state['db_local_path'] . '.raw.sql';

                // Pre-flight size snapshot — one information_schema query, used
                // by the chunk loop to log each table's size as it starts. Filter
                // down to just the tables we'll actually export so unrelated
                // schemas don't bloat the persisted state.
                $all_sizes   = $settings->get_tables_with_size();
                $table_sizes = array_intersect_key( $all_sizes, array_flip( $tables ) );

                $state['db_export'] = [
                    'tables'              => $tables,
                    'tables_exported'     => 0,
                    'raw_path'            => $raw_path,
                    'streamlined_config'  => $streamlined ? $exporter->get_streamlined_config() : null,
                    'table_sizes'         => $table_sizes,
                    'current_table'       => null,
                    'current_table_pk'    => null,
                    'current_table_last_pk' => null,
                ];
                $this->save_state( $state );

                Mighty_Backup_Log_Stream::add(
                    'Exporting database via PHP'
                    . ( $streamlined ? ' (streamlined)' : '' )
                    . ' — ' . count( $tables ) . ' tables, chunked export'
                );
            }

            $db             = $state['db_export'];
            // Setting is the user-visible default; the long-standing filter
            // still wins for site-config overrides via wp-config.php.
            $chunk_seconds  = (int) apply_filters(
                'mighty_backup_db_chunk_seconds',
                (int) $settings->get( 'db_chunk_seconds', 30 )
            );

            // Chunked mysqldump branch — process big-table PK ranges. Each
            // mysqldump --where invocation is one slice; loop until either the
            // chunk budget elapses or all big tables are done.
            if ( ( $db['method'] ?? null ) === 'mysqldump_chunked' ) {
                $this->run_mysqldump_chunked_step( $state, $exporter, $chunk_seconds );
                return;
            }

            // Process the next chunk of tables (PHP path).
            // First chunk = file preamble not yet written = no progress at all.
            $is_first_chunk = ( $db['tables_exported'] === 0 && empty( $db['current_table'] ) );

            $exporter->set_table_sizes( $db['table_sizes'] ?? [] );

            // Build the resume token if a prior chunk paused mid-table. The token
            // is only honored by export_tables_chunk when $tables[$tables_exported]
            // matches $current_table; defensive against table-list reshuffling
            // (which shouldn't happen — get_table_list is deterministic per call).
            $resume = null;
            if ( ! empty( $db['current_table'] ) && ! empty( $db['current_table_pk'] ) ) {
                $resume = [
                    'table'     => $db['current_table'],
                    'pk_column' => $db['current_table_pk'],
                    'last_pk'   => $db['current_table_last_pk'],
                ];
            }

            $chunk_result = $exporter->export_tables_chunk(
                $db['raw_path'],
                $db['tables'],
                $db['tables_exported'],
                $chunk_seconds,
                $is_first_chunk,
                $db['streamlined_config'],
                $resume
            );

            $next_index = $chunk_result['next_index'];
            $partial    = $chunk_result['partial'];

            $state['db_export']['tables_exported']       = $next_index;
            $state['db_export']['current_table']         = $partial['table']     ?? null;
            $state['db_export']['current_table_pk']      = $partial['pk_column'] ?? null;
            $state['db_export']['current_table_last_pk'] = $partial['last_pk']   ?? null;
            $this->save_state( $state );

            $total = count( $db['tables'] );
            if ( $partial !== null ) {
                Mighty_Backup_Log_Stream::add( sprintf(
                    'Paused on %s at %s=%s (%d/%d tables complete) — resuming in next chunk',
                    $partial['table'],
                    $partial['pk_column'],
                    (string) $partial['last_pk'],
                    $next_index,
                    $total
                ) );
            } else {
                Mighty_Backup_Log_Stream::add( "Exported {$next_index}/{$total} tables" );
            }

            if ( $next_index < $total || $partial !== null ) {
                // More work remains — either more tables, or the current one
                // is mid-export. Re-schedule this same step.
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

        } catch ( \Throwable $e ) {
            // Clean up raw temp file on failure.
            if ( isset( $state['db_export']['raw_path'] ) && file_exists( $state['db_export']['raw_path'] ) ) {
                @unlink( $state['db_export']['raw_path'] );
            }
            $this->fail( $state, 'Database export failed: ' . $e->getMessage() );
        }
    }

    /**
     * Resolve the next big-table cursor for the chunked-mysqldump path.
     *
     * Implements the no-PK Hybrid B→D graceful-degradation chain:
     *   B (range)            — table has a usable monotonic cursor (PRIMARY KEY,
     *                          UNIQUE NOT NULL, or auto_increment). Numeric or
     *                          string cursors both feed the existing range
     *                          pipeline at run_mysqldump_chunked_step().
     *   D (singleshot_full)  — no usable cursor exists. Issue ONE full
     *                          mysqldump-with-data invocation for the table
     *                          in its own Action Scheduler chunk with
     *                          set_time_limit(0). Schema is already in the
     *                          raw file from the upfront pass.
     *
     * Empty tables and tables that produce no cursor candidates are skipped
     * after their schema-only dump; the for-loop advances past them so the
     * caller doesn't need to filter again.
     *
     * @return array{
     *   index: int,
     *   mode: 'range'|'singleshot_full'|'done',
     *   pk: ?string,
     *   numeric: bool,
     *   source: ?string,
     *   max_pk: mixed,
     *   last_pk: mixed,
     *   range_size: int,
     * }
     */
    private function init_big_table_state(
        Mighty_Backup_Database_Exporter $exporter,
        string $raw_path,
        array $big_list,
        int $start_index
    ): array {
        $count = count( $big_list );
        for ( $i = $start_index; $i < $count; $i++ ) {
            $table  = $big_list[ $i ];
            $cursor = $exporter->get_table_cursor_column( $table );

            if ( $cursor === null ) {
                // No PK, no UNIQUE NOT NULL, no auto_increment. Falls through to
                // the singleshot full dump (D in the no-PK plan). The dump
                // runs unbounded; set_time_limit(0) is applied inside
                // dump_table_full_via_mysqldump.
                Mighty_Backup_Log_Stream::add(
                    "Table {$table}: no usable cursor — single-shot mysqldump (this chunk may run longer)"
                );
                return [
                    'index'      => $i,
                    'mode'       => 'singleshot_full',
                    'pk'         => null,
                    'numeric'    => false,
                    'source'     => null,
                    'max_pk'     => null,
                    'last_pk'    => null,
                    'range_size' => 0,
                ];
            }

            $bounds = $exporter->get_pk_bounds( $table, $cursor['column'] );
            if ( $bounds['max'] === null ) {
                // Empty table — schema already dumped; nothing to do here.
                continue;
            }

            if ( $cursor['numeric'] ) {
                $rows_estimate = max( 1, (int) $bounds['max'] - (int) ( $bounds['min'] ?? 0 ) + 1 );
                $initial_range = (int) max( 100000, min( 1000000, $rows_estimate / 20 ) );
                // Start at min-1 so the first --where `cursor > last` includes the min row.
                $start_after   = (int) ( $bounds['min'] ?? 0 ) - 1;
                $source_label  = $cursor['source'] === 'pk' ? 'PK' : strtoupper( $cursor['source'] );
                Mighty_Backup_Log_Stream::add( sprintf(
                    'Big table %s: ranged mysqldump via %s (%s), %s..%s (initial range %d rows)',
                    $table,
                    $source_label,
                    $cursor['column'],
                    (string) ( $bounds['min'] ?? 0 ),
                    (string) $bounds['max'],
                    $initial_range
                ) );
            } else {
                // Non-numeric cursor (VARCHAR, UUID, hash). Use a fixed initial
                // range row-count; the run loop computes the upper bound via a
                // separate indexed LIMIT seek for each range.
                $initial_range = 50000;
                $start_after   = null; // sentinel: "before min" for string comparisons
                $source_label  = $cursor['source'] === 'pk' ? 'PK' : strtoupper( $cursor['source'] );
                Mighty_Backup_Log_Stream::add( sprintf(
                    'Big table %s: ranged mysqldump via %s (%s, string cursor), %s..%s (initial range %d rows)',
                    $table,
                    $source_label,
                    $cursor['column'],
                    (string) ( $bounds['min'] ?? '' ),
                    (string) $bounds['max'],
                    $initial_range
                ) );
            }

            return [
                'index'      => $i,
                'mode'       => 'range',
                'pk'         => $cursor['column'],
                'numeric'    => $cursor['numeric'],
                'source'     => $cursor['source'],
                'max_pk'     => $bounds['max'],
                'last_pk'    => $start_after,
                'range_size' => $initial_range,
            ];
        }

        // No more big tables — caller should finalize.
        return [
            'index'      => $count,
            'mode'       => 'done',
            'pk'         => null,
            'numeric'    => false,
            'source'     => null,
            'max_pk'     => null,
            'last_pk'    => null,
            'range_size' => 0,
        ];
    }

    /**
     * Execute one chunk's worth of big-table dumps. Dispatches on each table's
     * mode (set by init_big_table_state):
     *   'range'            — loop --where ranges adaptively against chunk_seconds
     *   'singleshot_full'  — one mysqldump-with-data invocation, consuming one
     *                        Action Scheduler chunk regardless of budget
     *   'done'             — caller finalizes
     *
     * Numeric cursors compute the next upper bound arithmetically; string
     * cursors do an indexed LIMIT seek via next_cursor_upper_bound().
     */
    private function run_mysqldump_chunked_step(
        array $state,
        Mighty_Backup_Database_Exporter $exporter,
        int $chunk_seconds
    ): void {
        $db        = $state['db_export'];
        $raw_path  = $db['raw_path'];
        $big_list  = $db['big_tables'];
        $total     = count( $big_list );
        // Reserve ~30% headroom inside the budget so a slightly-slow range
        // doesn't carry us past the wall-time cap.
        $budget    = max( 5, (int) ( $chunk_seconds * 0.7 ) );
        $started   = time();
        $exporter->set_table_sizes( $db['table_sizes'] ?? [] );

        while ( $db['big_tables_index'] < $total ) {
            if ( ( time() - $started ) >= $budget ) {
                break;
            }

            $table = $big_list[ $db['big_tables_index'] ];
            $mode  = $db['current_table_mode'] ?? 'range';

            if ( $mode === 'singleshot_full' ) {
                // No usable cursor — run one full mysqldump for this table.
                // Consumes this whole AS chunk; advances index unconditionally
                // afterwards (success or throw — fail() bubbles via the outer
                // catch in step_export_db).
                $info = $exporter->dump_table_full_via_mysqldump( $raw_path, $table );
                Mighty_Backup_Log_Stream::add( sprintf(
                    '%s: full-table dump complete in %.1fs (+%s)',
                    $table,
                    (float) $info['elapsed'],
                    size_format( (int) $info['bytes'] )
                ) );
                $next = $this->init_big_table_state(
                    $exporter, $raw_path, $big_list, $db['big_tables_index'] + 1
                );
                $db = $this->merge_big_table_state( $db, $next );
                continue;
            }

            // mode === 'range'
            $pk        = $db['current_table_pk'];
            $numeric   = (bool) ( $db['current_table_numeric'] ?? true );
            $max_pk    = $db['current_table_max_pk'];
            $last_pk   = $db['current_table_last_pk'];
            $range     = (int) $db['current_table_range_size'];

            if ( $pk === null || $max_pk === null ) {
                // Defensive: shouldn't happen because init_big_table_state
                // either returns a range table or a singleshot_full mode.
                $next = $this->init_big_table_state( $exporter, $raw_path, $big_list, $db['big_tables_index'] + 1 );
                $db = $this->merge_big_table_state( $db, $next );
                continue;
            }

            if ( $numeric ) {
                $end_pk = (int) $last_pk + $range;
                if ( $end_pk > (int) $max_pk ) {
                    $end_pk = (int) $max_pk;
                }
                $range_from = (string) ( (int) $last_pk + 1 );
                $range_to   = (string) $end_pk;
            } else {
                // String cursor: ask the DB for the (range_size)-th value
                // greater than last_pk. last_pk === null on the very first
                // range scan; the seek treats that as "before the table's min"
                // by passing the empty string as the lower bound (lexicographic
                // strings are >= '').
                $start_after = $last_pk === null ? '' : (string) $last_pk;
                $end_pk      = $exporter->next_cursor_upper_bound( $table, $pk, $start_after, $range, $max_pk );
                $range_from  = $start_after === '' ? '(min)' : (string) $start_after;
                $range_to    = (string) $end_pk;
            }

            $info = $exporter->dump_table_range_via_mysqldump(
                $raw_path, $table, $pk, $last_pk === null ? '' : $last_pk, $end_pk
            );

            // Adapt the next range so it lands at ~70% of chunk_seconds.
            $elapsed = max( 0.1, (float) $info['elapsed'] );
            $target  = (int) ( $range * ( $chunk_seconds * 0.7 ) / $elapsed );
            $range   = (int) max( 10000, min( 10000000, $target ) );

            $db['current_table_last_pk']    = $end_pk;
            $db['current_table_range_size'] = $range;

            Mighty_Backup_Log_Stream::add( sprintf(
                '%s: dumped %s..%s in %.1fs (next range ~%d rows)',
                $table,
                $range_from,
                $range_to,
                $elapsed,
                $range
            ) );

            $done = $numeric ? ( $end_pk >= (int) $max_pk ) : ( (string) $end_pk === (string) $max_pk );
            if ( $done ) {
                // Table done — advance to next big table.
                $next = $this->init_big_table_state(
                    $exporter, $raw_path, $big_list, $db['big_tables_index'] + 1
                );
                $db = $this->merge_big_table_state( $db, $next );
            }
        }

        $state['db_export'] = $db;
        $this->save_state( $state );

        if ( $db['big_tables_index'] < $total ) {
            // More work remains.
            Mighty_Backup_Log_Stream::flush();
            as_schedule_single_action( time(), 'mighty_backup_step_export_db', [], self::ACTION_GROUP );
            return;
        }

        // All big tables done — finalize.
        Mighty_Backup_Log_Stream::add( 'Compressing database export...' );
        $size = $exporter->finalize_mysqldump_chunked( $raw_path, $state['db_local_path'] );

        $state['db_file_size'] = $size;
        unset( $state['db_export'] );
        $this->save_state( $state );

        Mighty_Backup_Log_Stream::add( 'Database export complete (' . size_format( $size ) . ')' );
        do_action( 'mighty_backup_after_export_db', $state, $state['db_local_path'] );

        $this->advance( $state );
    }

    /**
     * Merge a freshly-initialized big-table sub-state (from init_big_table_state)
     * into the running db_export state, replacing all per-table cursors.
     */
    private function merge_big_table_state( array $db, array $next ): array {
        $db['big_tables_index']         = $next['index'];
        $db['current_table_mode']       = $next['mode'];
        $db['current_table_pk']         = $next['pk'];
        $db['current_table_numeric']    = $next['numeric'];
        $db['current_table_source']     = $next['source'];
        $db['current_table_max_pk']     = $next['max_pk'];
        $db['current_table_last_pk']    = $next['last_pk'];
        $db['current_table_range_size'] = $next['range_size'];
        return $db;
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

        // Idempotency: AS re-fire after the archive completed but before
        // advance() ran. Skip the multi-GB tar rebuild.
        if ( ! empty( $state['files_file_size'] ) && file_exists( $state['files_local_path'] ) ) {
            Mighty_Backup_Log_Stream::add( 'File archive already present, skipping rebuild' );
            $this->advance( $state );
            return;
        }

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

        } catch ( \Throwable $e ) {
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

        // Idempotency: AS re-fire after the upload completed but before
        // advance() ran. Skip the duplicate multi-GB Spaces re-PUT.
        if ( ! empty( $state['db_remote_key'] ) ) {
            Mighty_Backup_Log_Stream::add( 'Database already uploaded, skipping' );
            $this->advance( $state );
            return;
        }

        do_action( 'mighty_backup_before_upload', $state, 'db' );

        try {
            $db_size_str = $state['db_file_size'] ? size_format( $state['db_file_size'] ) : 'unknown size';
            Mighty_Backup_Log_Stream::add( 'Uploading database (' . $db_size_str . ')...' );

            $settings = new Mighty_Backup_Settings();
            $client   = new Mighty_Backup_Spaces_Client( $settings );

            $stem       = $this->get_object_stem( $state );
            $remote_key = $client->upload(
                $state['db_local_path'],
                "databases/{$stem}-{$state['timestamp']}.sql.gz"
            );

            $state['db_remote_key'] = $remote_key;
            $this->save_state( $state );

            Mighty_Backup_Log_Stream::add( 'Database uploaded to Spaces' );

            do_action( 'mighty_backup_after_upload', $state, 'db', $remote_key );

            $this->advance( $state );

        } catch ( \Throwable $e ) {
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

        // Idempotency: AS re-fire after the upload completed but before
        // advance() ran. Skip the duplicate multi-GB Spaces re-PUT.
        if ( ! empty( $state['files_remote_key'] ) ) {
            Mighty_Backup_Log_Stream::add( 'Files archive already uploaded, skipping' );
            $this->advance( $state );
            return;
        }

        do_action( 'mighty_backup_before_upload', $state, 'files' );

        try {
            $files_size_str = $state['files_file_size'] ? size_format( $state['files_file_size'] ) : 'unknown size';
            Mighty_Backup_Log_Stream::add( 'Uploading files archive (' . $files_size_str . ')...' );

            $settings = new Mighty_Backup_Settings();
            $client   = new Mighty_Backup_Spaces_Client( $settings );

            $stem       = $this->get_object_stem( $state );
            $remote_key = $client->upload(
                $state['files_local_path'],
                "files/{$stem}-{$state['timestamp']}.tar.gz"
            );

            $state['files_remote_key'] = $remote_key;
            $this->save_state( $state );

            Mighty_Backup_Log_Stream::add( 'Files archive uploaded to Spaces' );

            do_action( 'mighty_backup_after_upload', $state, 'files', $remote_key );

            $this->advance( $state );

        } catch ( \Throwable $e ) {
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

        // Idempotency: AS re-fire after the previous cleanup already marked
        // the run completed. Bail without re-pruning + re-firing the
        // mighty_backup_completed hook (which listeners may treat as a new
        // success).
        if ( ( $state['status'] ?? '' ) === 'completed' ) {
            return;
        }

        Mighty_Backup_Log_Stream::add( 'Running retention cleanup...' );

        try {
            // Retention cleanup.
            $settings        = new Mighty_Backup_Settings();
            $client          = new Mighty_Backup_Spaces_Client( $settings );
            $retention_count = (int) $settings->get( 'retention_count', 7 );
            $retention       = new Mighty_Backup_Retention_Manager(
                $client,
                $retention_count,
                $this->get_object_stem( $state )
            );
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
        Mighty_Backup_Log_Stream::clear_progress();
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
     *
     * Race-check: if cancel() has set status='cancelling' (the tombstone)
     * while this step was running, skip the re-save. cancel() owns the
     * STATE_OPTION lifecycle from that point onward — re-saving 'failed'
     * here would resurrect the row cancel just nuked.
     */
    private function fail( array $state, string $error ): void {
        $fresh = $this->get_state();
        if ( is_array( $fresh ) && ( $fresh['status'] ?? '' ) === 'cancelling' ) {
            Mighty_Backup_Log_Stream::add( 'Step aborted by cancel: ' . $error );
            Mighty_Backup_Log_Stream::flush();
            return;
        }

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
     * Check if a backup is currently running. Returns true for both 'pending'
     * (queued but step_start hasn't flipped to 'running' yet) and 'running'
     * proper, AND for the transient 'cancelling' tombstone — anything else
     * (idle, completed, failed) means a new backup is safe to schedule.
     *
     * Including 'pending' closes the multi-second window between schedule()'s
     * save_state and step_start's status='running' flip during which a second
     * scheduler call could pass the gate and stomp the in-flight state.
     */
    public function is_running(): bool {
        $state = $this->get_state();
        return is_array( $state ) && in_array(
            $state['status'] ?? '',
            [ 'pending', 'running', 'cancelling' ],
            true
        );
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
        $state         = $this->get_state();
        $log_data      = Mighty_Backup_Log_Stream::get( $log_since );
        $live_progress = Mighty_Backup_Log_Stream::get_progress();

        if ( ! $state ) {
            return [
                'active'        => false,
                'status'        => 'idle',
                'message'       => 'No backup in progress.',
                'log_entries'   => $log_data['entries'],
                'log_index'     => $log_data['index'],
                'live_progress' => null,
            ];
        }

        $step_label = self::STEP_LABELS[ $state['current_step'] ] ?? $state['current_step'];
        $total      = count( $state['steps'] );
        $current    = $state['step_index'] + 1;

        // Sub-progress within the chunked DB export phase. The db_export state
        // has two shapes: the PHP path uses tables/tables_exported, while the
        // mysqldump-chunked path uses big_tables/big_tables_index and has no
        // 'tables' key. Branch on method so neither shape fatals on count().
        $sub_progress = null;
        if ( $state['current_step'] === 'export_db' && isset( $state['db_export'] ) ) {
            $db = $state['db_export'];

            if ( ( $db['method'] ?? null ) === 'mysqldump_chunked' ) {
                $total_tables = is_array( $db['big_tables'] ?? null ) ? count( $db['big_tables'] ) : 0;
                $exported     = (int) ( $db['big_tables_index'] ?? 0 );
            } else {
                $total_tables = is_array( $db['tables'] ?? null ) ? count( $db['tables'] ) : 0;
                $exported     = (int) ( $db['tables_exported'] ?? 0 );
            }

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

        $error_translated = null;
        if ( ! empty( $state['error'] ) && class_exists( 'Mighty_Backup_Error_Translator' ) ) {
            $error_translated = Mighty_Backup_Error_Translator::translate( $state['error'] );
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
            'error_translated' => $error_translated,
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
            'log_entries'   => $log_data['entries'],
            'log_index'     => $log_data['index'],
            'live_progress' => $live_progress,
        ];
    }

    /**
     * Process the next pending backup action directly.
     *
     * Bypasses ActionScheduler_QueueRunner::run() which can be blocked by
     * stale claims, concurrent batch limits, or time limits. This claims
     * a single action from the 'mighty-backup' group, processes it, and
     * releases the claim.
     */
    public function process_next_action(): void {
        if ( ! class_exists( 'ActionScheduler_Store' ) ) {
            return;
        }

        $store = \ActionScheduler_Store::instance();
        $claim = $store->stake_claim( 1, null, [], self::ACTION_GROUP );

        try {
            foreach ( $claim->get_actions() as $action_id ) {
                \ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'Mighty Backup' );
            }
        } catch ( \Throwable $e ) {
            // Action-level errors are handled inside each step's try/catch.
            // Catching \Throwable (not just \Exception) covers PHP 7+
            // TypeError/Error subclasses — without this a stray TypeError
            // would skip release_claim() and wedge the AS claim globally
            // for every Action Scheduler user on the install. The finally
            // block guarantees release no matter what.
            error_log( 'Mighty Backup: process_next_action error — ' . $e->getMessage() );
        } finally {
            $store->release_claim( $claim );
        }
    }

    /**
     * Validate that a db_export sub-state matches one of the known shapes.
     * Returns false if the shape is unrecognized — caller should fail() the
     * backup rather than dereferencing missing keys at runtime.
     *
     * Recognized shapes:
     *   chunked-mysqldump: {method: 'mysqldump_chunked', raw_path, big_tables, big_tables_index}
     *   PHP path:          {raw_path, tables, tables_exported}
     *
     * Single-shot mysqldump never populates db_export at all (it runs inline
     * in step_export_db), so its absence is not invalid — the caller checks
     * isset() before calling here.
     */
    private function validate_db_export_shape( $db ): bool {
        if ( ! is_array( $db ) ) {
            return false;
        }
        $is_chunked = ( $db['method'] ?? null ) === 'mysqldump_chunked';
        if ( $is_chunked ) {
            $required = [ 'raw_path', 'big_tables', 'big_tables_index' ];
            foreach ( $required as $key ) {
                if ( ! array_key_exists( $key, $db ) ) {
                    return false;
                }
            }
            return is_array( $db['big_tables'] );
        }
        // PHP path.
        $required = [ 'raw_path', 'tables', 'tables_exported' ];
        foreach ( $required as $key ) {
            if ( ! array_key_exists( $key, $db ) ) {
                return false;
            }
        }
        return is_array( $db['tables'] );
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

        // Tombstone: write status='cancelling' BEFORE unscheduling so a
        // mid-flight step's catch -> fail() can detect the cancel via
        // get_state() and skip the re-save that would otherwise resurrect
        // STATE_OPTION as 'failed' just after cancel deletes it.
        $state['status']       = 'cancelling';
        $state['cancelled_at'] = time();
        $this->save_state( $state );

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
     *
     * On single-site WP, prefer autoload=no via add_option on first write so
     * the (sometimes 50–100KB) serialized state with table_sizes etc. isn't
     * loaded on every wp-admin page hit. Subsequent writes use update_option,
     * which preserves the existing autoload flag. add_site_option / multisite
     * use wp_sitemeta which has no autoload concept — no special handling
     * needed there.
     */
    private function save_state( array $state ): void {
        if ( is_multisite() ) {
            update_site_option( self::STATE_OPTION, $state );
            return;
        }
        // Single-site: avoid autoload bloat.
        if ( get_option( self::STATE_OPTION, null ) === null ) {
            add_option( self::STATE_OPTION, $state, '', 'no' );
        } else {
            update_option( self::STATE_OPTION, $state );
        }
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
     * The object-name stem for this run, as claimed at start().
     *
     * A backup that was already in flight when the plugin updated has no
     * `object_stem` in its state, so fall back to the historical stem rather
     * than re-reading settings — that keeps its two archives paired and keeps
     * retention pointed at the same keys the upload steps wrote.
     */
    private function get_object_stem( array $state ): string {
        $stem = (string) ( $state['object_stem'] ?? '' );
        return $stem !== '' ? $stem : Mighty_Backup_Settings::DEFAULT_OBJECT_STEM;
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
