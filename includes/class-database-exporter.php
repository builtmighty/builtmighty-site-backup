<?php
/**
 * Database exporter — mysqldump with gzip (preferred) or pure PHP fallback.
 *
 * Supports "Streamlined Mode" for lighter exports:
 *   - mysqldump handles bulk tables (with --ignore-table for special ones)
 *   - PHP appends filtered order tables and structure-only log tables
 *   - Falls back to full PHP export when mysqldump is unavailable
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Database_Exporter {

    private int $batch_size;
    private int $insert_batch = 100;
    private bool $streamlined;

    public function __construct( bool $streamlined = false ) {
        $this->batch_size   = (int) apply_filters( 'mighty_backup_db_batch_size', 1000 );
        $this->streamlined  = $streamlined;
    }

    /**
     * Export the database to a gzipped SQL file.
     *
     * @param string $output_path Absolute path for the output .sql.gz file.
     * @return int File size in bytes.
     * @throws \Exception On export failure.
     */
    public function export( string $output_path ): int {
        if ( $this->streamlined ) {
            if ( $this->can_use_mysqldump() ) {
                return $this->export_streamlined_hybrid( $output_path );
            }
            return $this->export_streamlined_php( $output_path );
        }

        if ( $this->can_use_mysqldump() ) {
            return $this->export_with_mysqldump( $output_path );
        }

        return $this->export_with_php( $output_path );
    }

    /**
     * Check if mysqldump is available on the system.
     */
    private function can_use_mysqldump(): bool {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }

        $disabled = explode( ',', ini_get( 'disable_functions' ) );
        $disabled = array_map( 'trim', $disabled );
        if ( in_array( 'exec', $disabled, true ) ) {
            return false;
        }

        exec( 'which mysqldump 2>/dev/null', $output, $return_code );
        return $return_code === 0;
    }

    // ──────────────────────────────────────────────
    //  Full export paths (original behavior)
    // ──────────────────────────────────────────────

    /**
     * Export using mysqldump piped to gzip (fast, low memory).
     */
    private function export_with_mysqldump( string $output_path ): int {
        $gzip_level = $this->get_gzip_level();
        $err_path   = $output_path . '.err';
        $defaults   = $this->write_mysql_defaults();

        try {
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables --set-charset '
                . '--default-character-set=utf8mb4 --no-tablespaces '
                . '%s 2>%s | gzip -%d > %s',
                escapeshellarg( $defaults ),
                escapeshellarg( DB_NAME ),
                escapeshellarg( $err_path ),
                $gzip_level,
                escapeshellarg( $output_path )
            );

            Mighty_Backup_Log_Stream::add( 'Using mysqldump for database export' );

            exec( $command, $output, $return_code );

            $stderr = file_exists( $err_path ) ? trim( (string) file_get_contents( $err_path ) ) : '';
            @unlink( $err_path );

            if ( $return_code !== 0 || ! empty( $stderr ) ) {
                throw new \Exception( "mysqldump failed (exit {$return_code}): {$stderr}" );
            }

            $size = filesize( $output_path );
            if ( $size === false || $size === 0 ) {
                throw new \Exception( 'mysqldump produced an empty file — check database credentials.' );
            }

            return $size;
        } finally {
            @unlink( $defaults );
        }
    }

    /**
     * Export using pure PHP via $wpdb (fallback when mysqldump is unavailable).
     */
    private function export_with_php( string $output_path ): int {
        global $wpdb;

        if ( ! function_exists( 'gzopen' ) ) {
            throw new \Exception( 'The zlib PHP extension is required for database export.' );
        }

        $gzip_level = $this->get_gzip_level();
        $gz = gzopen( $output_path, 'wb' . $gzip_level );
        if ( ! $gz ) {
            throw new \Exception( "Failed to open output file: {$output_path}" );
        }

        try {
            $this->write_preamble( $gz );
            $tables      = $this->get_tables();
            $table_count = count( $tables );

            Mighty_Backup_Log_Stream::add( 'Using PHP fallback for database export (' . $table_count . ' tables)' );

            foreach ( $tables as $i => $table ) {
                Mighty_Backup_Log_Stream::add( 'Exporting table ' . ( $i + 1 ) . '/' . $table_count . ': ' . $table );
                $this->export_table( $gz, $table );
            }

            $this->write_postamble( $gz );
        } finally {
            gzclose( $gz );
        }

        $size = filesize( $output_path );
        if ( $size === false ) {
            throw new \Exception( "Failed to read output file size: {$output_path}" );
        }

        return $size;
    }

    // ──────────────────────────────────────────────
    //  Streamlined export paths
    // ──────────────────────────────────────────────

    /**
     * Streamlined hybrid: mysqldump for bulk tables, PHP for special tables.
     *
     * 1. Runs mysqldump (uncompressed) to a temp file.
     * 2. Streams the mysqldump output + PHP special tables through a single gzip handle.
     *
     * This avoids concatenated gzip members (RFC 1952) which some tools don't support.
     */
    private function export_streamlined_hybrid( string $output_path ): int {
        $special = $this->get_streamlined_special_tables();
        $gzip_level = $this->get_gzip_level();

        // Step 1: mysqldump everything except special tables to a temp SQL file.
        $ignore_tables = array_merge(
            $special['log_tables'],
            array_keys( $special['order_tables'] )
        );

        // If legacy mode, also exclude posts and postmeta from mysqldump.
        if ( ! empty( $special['legacy'] ) ) {
            global $wpdb;
            $ignore_tables[] = $wpdb->posts;
            $ignore_tables[] = $wpdb->postmeta;
        }

        $ignore_flags = '';
        foreach ( $ignore_tables as $table ) {
            $ignore_flags .= ' --ignore-table=' . escapeshellarg( DB_NAME . '.' . $table );
        }

        Mighty_Backup_Log_Stream::add( 'Using mysqldump (streamlined) for bulk tables' );

        $raw_path = $output_path . '.raw.sql';
        $err_path = $output_path . '.err';
        $defaults = $this->write_mysql_defaults();

        try {
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables --set-charset '
                . '--default-character-set=utf8mb4 --no-tablespaces '
                . '%s %s > %s 2>%s',
                escapeshellarg( $defaults ),
                $ignore_flags,
                escapeshellarg( DB_NAME ),
                escapeshellarg( $raw_path ),
                escapeshellarg( $err_path )
            );

            exec( $command, $output, $return_code );
        } finally {
            @unlink( $defaults );
        }

        $stderr = file_exists( $err_path ) ? trim( (string) file_get_contents( $err_path ) ) : '';
        @unlink( $err_path );

        if ( $return_code !== 0 ) {
            @unlink( $raw_path );
            throw new \Exception( "mysqldump failed (exit {$return_code}): {$stderr}" );
        }

        // Step 2: Stream mysqldump SQL + special tables through a single gzip handle.
        $gz = gzopen( $output_path, 'wb' . $gzip_level );
        if ( ! $gz ) {
            @unlink( $raw_path );
            throw new \Exception( "Failed to open output file for writing: {$output_path}" );
        }

        try {
            // Stream the raw mysqldump SQL into the gzip handle in chunks.
            $fh = fopen( $raw_path, 'rb' );
            if ( ! $fh ) {
                throw new \Exception( "Failed to read mysqldump output: {$raw_path}" );
            }
            while ( ! feof( $fh ) ) {
                $chunk = fread( $fh, 65536 ); // 64 KB chunks.
                if ( $chunk !== false && $chunk !== '' ) {
                    gzwrite( $gz, $chunk );
                }
            }
            fclose( $fh );

            // Append special tables via PHP into the same gzip stream.
            Mighty_Backup_Log_Stream::add( 'Appending filtered/structure-only tables via PHP' );
            $this->export_streamlined_special_tables( $gz, $special );
        } finally {
            gzclose( $gz );
            @unlink( $raw_path );
        }

        $size = filesize( $output_path );
        if ( $size === false || $size === 0 ) {
            throw new \Exception( 'Streamlined export produced an empty file.' );
        }

        return $size;
    }

    /**
     * Streamlined PHP-only: full PHP export with per-table routing.
     */
    private function export_streamlined_php( string $output_path ): int {
        if ( ! function_exists( 'gzopen' ) ) {
            throw new \Exception( 'The zlib PHP extension is required for database export.' );
        }

        $special    = $this->get_streamlined_special_tables();
        $gzip_level = $this->get_gzip_level();
        $gz = gzopen( $output_path, 'wb' . $gzip_level );
        if ( ! $gz ) {
            throw new \Exception( "Failed to open output file: {$output_path}" );
        }

        try {
            global $wpdb;

            $this->write_preamble( $gz );
            $tables      = $this->get_tables();
            $table_count = count( $tables );

            Mighty_Backup_Log_Stream::add( 'Using PHP fallback for streamlined export (' . $table_count . ' tables)' );

            $log_tables   = $special['log_tables'];
            $order_tables = $special['order_tables'];
            $is_legacy    = ! empty( $special['legacy'] );

            $order_ids      = $this->get_recent_order_ids();
            $order_item_ids = $this->get_order_item_ids( $order_ids );

            foreach ( $tables as $i => $table ) {
                Mighty_Backup_Log_Stream::add( 'Exporting table ' . ( $i + 1 ) . '/' . $table_count . ': ' . $table );
                if ( in_array( $table, $log_tables, true ) ) {
                    // Structure only for log tables.
                    $this->export_table_structure_only( $gz, $table );
                } elseif ( isset( $order_tables[ $table ] ) ) {
                    // Filtered export for order tables.
                    $id_column  = $order_tables[ $table ];
                    $id_list    = ( $id_column === 'order_item_id' ) ? $order_item_ids : $order_ids;
                    $this->export_table_filtered( $gz, $table, $id_column, $id_list );
                } elseif ( $is_legacy && $table === $wpdb->posts ) {
                    $this->export_table_posts_streamlined( $gz, $table, $order_ids );
                } elseif ( $is_legacy && $table === $wpdb->postmeta ) {
                    $this->export_table_postmeta_streamlined( $gz, $table, $order_ids );
                } else {
                    // Normal full export.
                    $this->export_table( $gz, $table );
                }
            }

            $this->write_postamble( $gz );
        } finally {
            gzclose( $gz );
        }

        $size = filesize( $output_path );
        if ( $size === false ) {
            throw new \Exception( "Failed to read output file size: {$output_path}" );
        }

        return $size;
    }

    /**
     * Export the special tables (log + order) for the streamlined hybrid path.
     */
    private function export_streamlined_special_tables( $gz, array $special ): void {
        global $wpdb;

        gzwrite( $gz, "\n-- Streamlined export: filtered tables\n" );
        gzwrite( $gz, "SET FOREIGN_KEY_CHECKS = 0;\n\n" );

        // Log tables: structure only.
        foreach ( $special['log_tables'] as $table ) {
            $this->export_table_structure_only( $gz, $table );
        }

        // Order tables: filtered by recent order IDs.
        $order_ids      = $this->get_recent_order_ids();
        $order_item_ids = $this->get_order_item_ids( $order_ids );

        foreach ( $special['order_tables'] as $table => $id_column ) {
            $id_list = ( $id_column === 'order_item_id' ) ? $order_item_ids : $order_ids;
            $this->export_table_filtered( $gz, $table, $id_column, $id_list );
        }

        // Legacy posts/postmeta handling.
        if ( ! empty( $special['legacy'] ) ) {
            $this->export_table_posts_streamlined( $gz, $wpdb->posts, $order_ids );
            $this->export_table_postmeta_streamlined( $gz, $wpdb->postmeta, $order_ids );
        }

        gzwrite( $gz, "\nSET FOREIGN_KEY_CHECKS = 1;\nCOMMIT;\n" );
    }

    // ──────────────────────────────────────────────
    //  Streamlined helper methods
    // ──────────────────────────────────────────────

    /**
     * Identify tables that need special handling in streamlined mode.
     *
     * @return array{log_tables: string[], order_tables: array<string, string>, legacy: bool}
     */
    private function get_streamlined_special_tables(): array {
        global $wpdb;

        $all_tables = $this->get_tables();
        $prefix     = $wpdb->prefix;

        // Log tables — structure only, no data.
        $log_tables = [];
        $action_scheduler_tables = [
            $prefix . 'actionscheduler_actions',
            $prefix . 'actionscheduler_claims',
            $prefix . 'actionscheduler_groups',
            $prefix . 'actionscheduler_logs',
        ];

        foreach ( $all_tables as $table ) {
            $is_log = false;

            // Action Scheduler tables.
            if ( in_array( $table, $action_scheduler_tables, true ) ) {
                $is_log = true;
            }

            // Plugin log table.
            if ( $table === $prefix . 'bm_backup_log' ) {
                $is_log = true;
            }

            // Tables ending in _log or _logs.
            if ( preg_match( '/_logs?$/', $table ) ) {
                $is_log = true;
            }

            // Filterable.
            $is_log = (bool) apply_filters( 'mighty_backup_is_log_table', $is_log, $table );

            if ( $is_log ) {
                $log_tables[] = $table;
            }
        }

        // Order tables — filtered by recent order IDs.
        $order_tables = [];

        // HPOS tables.
        $hpos_tables = [
            $prefix . 'wc_orders'                  => 'id',
            $prefix . 'wc_orders_meta'              => 'order_id',
            $prefix . 'wc_order_addresses'          => 'order_id',
            $prefix . 'wc_order_operational_data'   => 'order_id',
        ];

        $has_hpos = $this->table_exists( $prefix . 'wc_orders' );

        if ( $has_hpos ) {
            foreach ( $hpos_tables as $table => $id_col ) {
                if ( $this->table_exists( $table ) ) {
                    $order_tables[ $table ] = $id_col;
                }
            }
        }

        // Shared WooCommerce tables (present regardless of HPOS).
        $shared_tables = [
            $prefix . 'woocommerce_order_items'    => 'order_id',
            $prefix . 'woocommerce_order_itemmeta' => 'order_item_id',
            $prefix . 'wc_order_stats'             => 'order_id',
        ];

        foreach ( $shared_tables as $table => $id_col ) {
            if ( $this->table_exists( $table ) ) {
                $order_tables[ $table ] = $id_col;
            }
        }

        // Filterable.
        $order_tables = (array) apply_filters( 'mighty_backup_order_table_config', $order_tables );

        // Legacy flag: orders live in wp_posts when HPOS table doesn't exist.
        $legacy = ! $has_hpos;

        return [
            'log_tables'   => $log_tables,
            'order_tables' => $order_tables,
            'legacy'       => $legacy,
        ];
    }

    /**
     * Get order IDs from the last N days.
     *
     * @return array<int> Order IDs.
     */
    private function get_recent_order_ids(): array {
        global $wpdb;

        $days     = (int) apply_filters( 'mighty_backup_streamlined_days', 90 );
        $cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $prefix   = $wpdb->prefix;

        // Try HPOS table first.
        if ( $this->table_exists( $prefix . 'wc_orders' ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return array_map( 'intval', $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM `{$prefix}wc_orders` WHERE date_created_gmt >= %s",
                    $cutoff
                )
            ) );
        }

        // Fall back to legacy wp_posts.
        return array_map( 'intval', $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM `{$wpdb->posts}` WHERE post_type IN ('shop_order', 'shop_order_refund') AND post_date_gmt >= %s",
                $cutoff
            )
        ) );
    }

    /**
     * Get order item IDs for a set of order IDs.
     *
     * @param array<int> $order_ids
     * @return array<int> Order item IDs.
     */
    private function get_order_item_ids( array $order_ids ): array {
        global $wpdb;

        if ( empty( $order_ids ) ) {
            return [];
        }

        $prefix = $wpdb->prefix;
        $table  = $prefix . 'woocommerce_order_items';

        if ( ! $this->table_exists( $table ) ) {
            return [];
        }

        $item_ids = [];
        $chunks   = array_chunk( $order_ids, 500 );

        foreach ( $chunks as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT order_item_id FROM `{$table}` WHERE order_id IN ({$placeholders})",
                    ...$chunk
                )
            );
            $item_ids = array_merge( $item_ids, array_map( 'intval', $results ) );
        }

        return $item_ids;
    }

    /** @var array<string, true>|null Cached set of all table names. */
    private ?array $table_cache = null;

    /**
     * Check if a table exists in the database (uses a cached SHOW TABLES).
     */
    private function table_exists( string $table ): bool {
        if ( $this->table_cache === null ) {
            $this->table_cache = array_fill_keys( $this->get_tables(), true );
        }

        return isset( $this->table_cache[ $table ] );
    }

    // ──────────────────────────────────────────────
    //  Table export methods
    // ──────────────────────────────────────────────

    /**
     * Export a table's structure only (CREATE TABLE, no data).
     */
    private function export_table_structure_only( $gz, string $table ): void {
        global $wpdb;

        gzwrite( $gz, "--\n-- Table: `{$table}` (structure only — streamlined)\n--\n\n" );
        gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) {
            gzwrite( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
            return;
        }
        gzwrite( $gz, $create[1] . ";\n\n" );
    }

    /**
     * Export a table filtered by an ID column and allowed ID list.
     *
     * Exports structure + only rows where $id_column IN ($allowed_ids).
     */
    private function export_table_filtered( $gz, string $table, string $id_column, array $allowed_ids ): void {
        global $wpdb;

        // Structure first.
        gzwrite( $gz, "--\n-- Table: `{$table}` (filtered — streamlined)\n--\n\n" );
        gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) {
            gzwrite( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
            return;
        }
        gzwrite( $gz, $create[1] . ";\n\n" );

        // No data if no matching IDs.
        if ( empty( $allowed_ids ) ) {
            gzwrite( $gz, "-- No matching rows for streamlined export.\n\n" );
            return;
        }

        // Export data in chunks to avoid oversized queries.
        $chunks      = array_chunk( $allowed_ids, 500 );
        $pk_column   = $this->get_primary_key( $table );
        $binary_cols = $this->get_binary_columns( $table );

        foreach ( $chunks as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

            if ( $pk_column ) {
                // PK-paginated within the chunk.
                $last_id       = 0;
                $insert_buffer = [];

                while ( true ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM `{$table}` WHERE `{$id_column}` IN ({$placeholders}) AND `{$pk_column}` > %d ORDER BY `{$pk_column}` ASC LIMIT %d",
                            ...array_merge( $chunk, [ $last_id, $this->batch_size ] )
                        ),
                        ARRAY_A
                    );

                    if ( empty( $rows ) ) {
                        break;
                    }

                    foreach ( $rows as $row ) {
                        $last_id = $row[ $pk_column ];
                        $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                        if ( count( $insert_buffer ) >= $this->insert_batch ) {
                            $this->flush_inserts( $gz, $table, $insert_buffer );
                            $insert_buffer = [];
                        }
                    }

                    unset( $rows );
                }

                if ( ! empty( $insert_buffer ) ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                }
            } else {
                // Offset-based for tables without a PK.
                $offset        = 0;
                $batch_size    = 500;
                $insert_buffer = [];

                while ( true ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM `{$table}` WHERE `{$id_column}` IN ({$placeholders}) LIMIT %d OFFSET %d",
                            ...array_merge( $chunk, [ $batch_size, $offset ] )
                        ),
                        ARRAY_A
                    );

                    if ( empty( $rows ) ) {
                        break;
                    }

                    foreach ( $rows as $row ) {
                        $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                        if ( count( $insert_buffer ) >= $this->insert_batch ) {
                            $this->flush_inserts( $gz, $table, $insert_buffer );
                            $insert_buffer = [];
                        }
                    }

                    $offset += $batch_size;
                    unset( $rows );
                }

                if ( ! empty( $insert_buffer ) ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                }
            }
        }

        gzwrite( $gz, "\n" );
    }

    /**
     * Streamlined export for wp_posts (legacy order storage).
     *
     * Exports all non-order posts + only recent order posts.
     */
    private function export_table_posts_streamlined( $gz, string $table, array $order_ids ): void {
        global $wpdb;

        gzwrite( $gz, "--\n-- Table: `{$table}` (streamlined — legacy orders)\n--\n\n" );
        gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) {
            gzwrite( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
            return;
        }
        gzwrite( $gz, $create[1] . ";\n\n" );

        // Pass 1: All non-order posts.
        $binary_cols   = $this->get_binary_columns( $table );
        $last_id       = 0;
        $insert_buffer = [];

        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE post_type NOT IN ('shop_order', 'shop_order_refund') AND ID > %d ORDER BY ID ASC LIMIT %d",
                    $last_id,
                    $this->batch_size
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = $row['ID'];
                $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                if ( count( $insert_buffer ) >= $this->insert_batch ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                    $insert_buffer = [];
                }
            }

            unset( $rows );
        }

        if ( ! empty( $insert_buffer ) ) {
            $this->flush_inserts( $gz, $table, $insert_buffer );
        }

        // Pass 2: Recent order posts only.
        if ( ! empty( $order_ids ) ) {
            $chunks = array_chunk( $order_ids, 500 );

            foreach ( $chunks as $chunk ) {
                $placeholders  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
                $last_id       = 0;
                $insert_buffer = [];

                while ( true ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM `{$table}` WHERE post_type IN ('shop_order', 'shop_order_refund') AND ID IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d",
                            ...array_merge( $chunk, [ $last_id, $this->batch_size ] )
                        ),
                        ARRAY_A
                    );

                    if ( empty( $rows ) ) {
                        break;
                    }

                    foreach ( $rows as $row ) {
                        $last_id = $row['ID'];
                        $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                        if ( count( $insert_buffer ) >= $this->insert_batch ) {
                            $this->flush_inserts( $gz, $table, $insert_buffer );
                            $insert_buffer = [];
                        }
                    }

                    unset( $rows );
                }

                if ( ! empty( $insert_buffer ) ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                }
            }
        }

        gzwrite( $gz, "\n" );
    }

    /**
     * Streamlined export for wp_postmeta (legacy order storage).
     *
     * Exports all postmeta except for old (excluded) order IDs.
     */
    private function export_table_postmeta_streamlined( $gz, string $table, array $recent_order_ids ): void {
        global $wpdb;

        gzwrite( $gz, "--\n-- Table: `{$table}` (streamlined — legacy orders)\n--\n\n" );
        gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) {
            gzwrite( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
            return;
        }
        gzwrite( $gz, $create[1] . ";\n\n" );

        // Get all old order IDs (orders NOT in the recent set).
        $all_order_ids = array_map( 'intval', $wpdb->get_col(
            "SELECT ID FROM `{$wpdb->posts}` WHERE post_type IN ('shop_order', 'shop_order_refund')"
        ) );

        $old_order_ids = array_diff( $all_order_ids, $recent_order_ids );
        unset( $all_order_ids );

        if ( empty( $old_order_ids ) ) {
            // No old orders to exclude — export everything normally.
            $this->export_table_data_pk( $gz, $table, 'meta_id' );
            gzwrite( $gz, "\n" );
            return;
        }

        // Export postmeta excluding old order post_ids, using PK pagination.
        // We chunk the exclusion list and use NOT IN to keep memory bounded.
        $binary_cols   = $this->get_binary_columns( $table );
        $last_id       = 0;
        $insert_buffer = [];

        // Paginate through postmeta by PK and skip rows where post_id is in the old set.
        $old_set = array_flip( $old_order_ids );

        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE meta_id > %d ORDER BY meta_id ASC LIMIT %d",
                    $last_id,
                    $this->batch_size
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = $row['meta_id'];

                // Skip postmeta belonging to old orders.
                if ( isset( $old_set[ (int) $row['post_id'] ] ) ) {
                    continue;
                }

                $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                if ( count( $insert_buffer ) >= $this->insert_batch ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                    $insert_buffer = [];
                }
            }

            unset( $rows );
        }

        if ( ! empty( $insert_buffer ) ) {
            $this->flush_inserts( $gz, $table, $insert_buffer );
        }

        gzwrite( $gz, "\n" );
    }

    // ──────────────────────────────────────────────
    //  Shared methods (used by both full and streamlined paths)
    // ──────────────────────────────────────────────

    /**
     * Write a temporary MySQL defaults file for mysqldump authentication.
     *
     * Avoids MYSQL_PWD environment variable which is visible in /proc and
     * deprecated in MySQL 8.0.
     *
     * @return string Path to the temporary .cnf file. Caller must unlink when done.
     */
    private function write_mysql_defaults(): string {
        $path     = get_temp_dir() . 'bm-backup-my-' . wp_generate_password( 8, false ) . '.cnf';
        $contents = "[client]\n"
            . 'user=' . DB_USER . "\n"
            . 'password=' . DB_PASSWORD . "\n"
            . 'host=' . DB_HOST . "\n";
        file_put_contents( $path, $contents );
        chmod( $path, 0600 );
        return $path;
    }

    /**
     * Get the configured gzip compression level.
     */
    private function get_gzip_level(): int {
        return max( 1, min( 9, (int) apply_filters( 'mighty_backup_db_gzip_level', 3 ) ) );
    }

    /**
     * Write SQL preamble (character set, modes, etc).
     */
    private function write_preamble( $gz ): void {
        $header = "-- Mighty Backup\n"
                . "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n"
                . "-- Plugin Version: " . MIGHTY_BACKUP_VERSION . "\n"
                . ( $this->streamlined ? "-- Mode: Streamlined\n" : '' )
                . "\nSET NAMES utf8mb4;\n"
                . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
                . "SET FOREIGN_KEY_CHECKS = 0;\n"
                . "SET AUTOCOMMIT = 0;\n\n";
        gzwrite( $gz, $header );
    }

    /**
     * Write SQL postamble.
     */
    private function write_postamble( $gz ): void {
        $footer = "\nSET FOREIGN_KEY_CHECKS = 1;\n"
                . "COMMIT;\n";
        gzwrite( $gz, $footer );
    }

    /**
     * Get all tables in the database.
     */
    private function get_tables(): array {
        global $wpdb;
        return $wpdb->get_col( 'SHOW TABLES' );
    }

    /**
     * Export a single table (structure + data).
     */
    private function export_table( $gz, string $table ): void {
        global $wpdb;

        gzwrite( $gz, "--\n-- Table: `{$table}`\n--\n\n" );
        gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) {
            gzwrite( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
            return;
        }
        gzwrite( $gz, $create[1] . ";\n\n" );

        $pk_column = $this->get_primary_key( $table );

        if ( $pk_column ) {
            $this->export_table_data_pk( $gz, $table, $pk_column );
        } else {
            $this->export_table_data_offset( $gz, $table );
        }

        gzwrite( $gz, "\n" );
    }

    /**
     * Detect the primary key column for a table.
     *
     * @return string|null Column name, or null if no single-column PK.
     */
    private function get_primary_key( string $table ): ?string {
        global $wpdb;

        $keys = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW KEYS FROM `{$table}` WHERE Key_name = %s",
                'PRIMARY'
            ),
            ARRAY_A
        );

        if ( count( $keys ) === 1 ) {
            return $keys[0]['Column_name'];
        }

        return null;
    }

    /**
     * Export table data using primary-key-based pagination.
     */
    private function export_table_data_pk( $gz, string $table, string $pk_column ): void {
        global $wpdb;

        $binary_cols   = $this->get_binary_columns( $table );
        $last_id       = 0;
        $insert_buffer = [];

        while ( true ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE `{$pk_column}` > %s ORDER BY `{$pk_column}` ASC LIMIT %d",
                    $last_id,
                    $this->batch_size
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = $row[ $pk_column ];
                $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                if ( count( $insert_buffer ) >= $this->insert_batch ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                    $insert_buffer = [];
                }
            }

            unset( $rows );
        }

        if ( ! empty( $insert_buffer ) ) {
            $this->flush_inserts( $gz, $table, $insert_buffer );
        }
    }

    /**
     * Export table data using LIMIT/OFFSET (fallback for tables without a PK).
     */
    private function export_table_data_offset( $gz, string $table ): void {
        global $wpdb;

        $binary_cols   = $this->get_binary_columns( $table );
        $offset        = 0;
        $batch_size    = 500;
        $insert_buffer = [];

        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $insert_buffer[] = $this->build_values_string( $row, $binary_cols );

                if ( count( $insert_buffer ) >= $this->insert_batch ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                    $insert_buffer = [];
                }
            }

            $offset += $batch_size;
            unset( $rows );
        }

        if ( ! empty( $insert_buffer ) ) {
            $this->flush_inserts( $gz, $table, $insert_buffer );
        }
    }

    /**
     * Build the VALUES string for a single row.
     *
     * @param array $row          Associative row data.
     * @param array $binary_cols  Set of column names that are binary types (keys = names).
     */
    private function build_values_string( array $row, array $binary_cols = [] ): string {
        $values = [];
        foreach ( $row as $key => $value ) {
            if ( is_null( $value ) ) {
                $values[] = 'NULL';
            } elseif ( isset( $binary_cols[ $key ] ) && $value !== '' ) {
                $values[] = '0x' . bin2hex( $value );
            } else {
                $values[] = "'" . esc_sql( $value ) . "'";
            }
        }

        return '(' . implode( ',', $values ) . ')';
    }

    /**
     * Get the set of binary column names for a table.
     *
     * @return array Column names as keys (for isset() lookups).
     */
    private function get_binary_columns( string $table ): array {
        global $wpdb;

        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        $binary  = [];

        foreach ( $columns as $col ) {
            if ( preg_match( '/binary|blob|bit/i', $col['Type'] ) ) {
                $binary[ $col['Field'] ] = true;
            }
        }

        return $binary;
    }

    /**
     * Write a multi-row INSERT statement to the gzip stream.
     */
    private function flush_inserts( $gz, string $table, array $values_strings ): void {
        $sql = "INSERT INTO `{$table}` VALUES\n"
             . implode( ",\n", $values_strings )
             . ";\n";
        gzwrite( $gz, $sql );
    }
}
