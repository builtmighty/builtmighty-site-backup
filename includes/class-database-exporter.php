<?php
/**
 * Database exporter — pure PHP database dump using $wpdb with gzip streaming.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BM_Backup_Database_Exporter {

    private int $batch_size = 1000;
    private int $insert_batch = 100;

    /**
     * Export the entire database to a gzipped SQL file.
     *
     * @param string $output_path Absolute path for the output .sql.gz file.
     * @return int File size in bytes.
     * @throws \Exception On export failure.
     */
    public function export( string $output_path ): int {
        global $wpdb;

        if ( ! function_exists( 'gzopen' ) ) {
            throw new \Exception( 'The zlib PHP extension is required for database export.' );
        }

        $gz = gzopen( $output_path, 'wb6' );
        if ( ! $gz ) {
            throw new \Exception( "Failed to open output file: {$output_path}" );
        }

        try {
            $this->write_preamble( $gz );
            $tables = $this->get_tables();

            foreach ( $tables as $table ) {
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

    /**
     * Write SQL preamble (character set, modes, etc).
     */
    private function write_preamble( $gz ): void {
        $header = "-- BuiltMighty Site Backup\n"
                . "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n"
                . "-- Plugin Version: " . BM_BACKUP_VERSION . "\n\n"
                . "SET NAMES utf8mb4;\n"
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
     *
     * @return array Table names.
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

        // Table structure.
        gzwrite( $gz, "--\n-- Table: `{$table}`\n--\n\n" );
        gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || empty( $create[1] ) ) {
            gzwrite( $gz, "-- WARNING: Could not get CREATE TABLE for `{$table}`\n\n" );
            return;
        }
        gzwrite( $gz, $create[1] . ";\n\n" );

        // Table data.
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

        // Only use PK-based pagination for single-column primary keys.
        if ( count( $keys ) === 1 ) {
            return $keys[0]['Column_name'];
        }

        return null;
    }

    /**
     * Export table data using primary-key-based pagination (fast, constant performance).
     */
    private function export_table_data_pk( $gz, string $table, string $pk_column ): void {
        global $wpdb;

        $last_id      = 0;
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
                $insert_buffer[] = $this->build_values_string( $row );

                if ( count( $insert_buffer ) >= $this->insert_batch ) {
                    $this->flush_inserts( $gz, $table, $insert_buffer );
                    $insert_buffer = [];
                }
            }

            unset( $rows );
        }

        // Flush remaining rows.
        if ( ! empty( $insert_buffer ) ) {
            $this->flush_inserts( $gz, $table, $insert_buffer );
        }
    }

    /**
     * Export table data using LIMIT/OFFSET (fallback for tables without a PK).
     */
    private function export_table_data_offset( $gz, string $table ): void {
        global $wpdb;

        $offset        = 0;
        $batch_size    = 500; // Smaller batches for offset-based pagination.
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
                $insert_buffer[] = $this->build_values_string( $row );

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
     */
    private function build_values_string( array $row ): string {
        global $wpdb;

        $values = [];
        foreach ( $row as $value ) {
            if ( is_null( $value ) ) {
                $values[] = 'NULL';
            } else {
                $values[] = $wpdb->prepare( '%s', $value );
            }
        }

        return '(' . implode( ',', $values ) . ')';
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
