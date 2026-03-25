<?php
/**
 * Backup logger — custom DB table for tracking backup history.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Logger {

    /**
     * Get the log table name.
     */
    public function get_table_name(): string {
        global $wpdb;
        return $wpdb->base_prefix . 'bm_backup_log';
    }

    /**
     * Create the log table on activation.
     */
    public function create_table(): void {
        global $wpdb;
        $table   = $this->get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            backup_type VARCHAR(10) NOT NULL DEFAULT 'full',
            trigger_type VARCHAR(10) NOT NULL DEFAULT 'scheduled',
            status VARCHAR(10) NOT NULL DEFAULT 'running',
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            db_file_size BIGINT UNSIGNED NULL,
            files_file_size BIGINT UNSIGNED NULL,
            db_remote_key VARCHAR(500) NULL,
            files_remote_key VARCHAR(500) NULL,
            error_message TEXT NULL,
            INDEX idx_status (status),
            INDEX idx_started (started_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Create a new log entry and return its ID.
     */
    public function start( string $backup_type, string $trigger_type ): int {
        global $wpdb;
        $wpdb->insert(
            $this->get_table_name(),
            [
                'backup_type'  => $backup_type,
                'trigger_type' => $trigger_type,
                'status'       => 'running',
                'started_at'   => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Mark a log entry as completed.
     */
    public function complete( int $log_id, array $data = [] ): void {
        global $wpdb;
        $update = array_merge(
            [
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql', true ),
            ],
            $data
        );
        $wpdb->update( $this->get_table_name(), $update, [ 'id' => $log_id ] );
    }

    /**
     * Mark a log entry as failed.
     */
    public function fail( int $log_id, string $error_message ): void {
        global $wpdb;
        $wpdb->update(
            $this->get_table_name(),
            [
                'status'        => 'failed',
                'completed_at'  => current_time( 'mysql', true ),
                'error_message' => $error_message,
            ],
            [ 'id' => $log_id ]
        );
    }

    /**
     * Get recent log entries.
     */
    public function get_recent( int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $table = $this->get_table_name();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY started_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );
    }

    /**
     * Get the most recent completed backup.
     */
    public function get_last_completed(): ?array {
        global $wpdb;
        $table = $this->get_table_name();
        $row   = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1",
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Get total count of log entries.
     */
    public function get_count(): int {
        global $wpdb;
        $table = $this->get_table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }
}
