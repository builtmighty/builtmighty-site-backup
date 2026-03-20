<?php
/**
 * Live log stream — stores timestamped backup log entries in a site option
 * for real-time display in the admin UI via AJAX polling.
 *
 * Uses an in-memory buffer to avoid writing to the database on every add().
 * Call flush() at the end of each backup step to persist buffered entries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Backup_Log_Stream {

	private const OPTION_KEY    = 'bm_backup_live_log';
	private const MAX_ENTRIES   = 200;
	private const FLUSH_EVERY   = 10;

	/** @var array In-memory buffer of entries not yet persisted. */
	private static array $buffer = [];

	/**
	 * Clear any existing log entries. Call at backup start.
	 */
	public static function start(): void {
		self::$buffer = [];
		delete_site_option( self::OPTION_KEY );
	}

	/**
	 * Append a timestamped log entry. Flushes to DB every FLUSH_EVERY entries.
	 */
	public static function add( string $message ): void {
		self::$buffer[] = [
			'time'    => gmdate( 'H:i:s' ),
			'message' => $message,
		];

		if ( count( self::$buffer ) >= self::FLUSH_EVERY ) {
			self::flush();
		}
	}

	/**
	 * Persist any buffered entries to the database.
	 */
	public static function flush(): void {
		if ( empty( self::$buffer ) ) {
			return;
		}

		$log = get_site_option( self::OPTION_KEY, [] );
		$log = array_merge( $log, self::$buffer );
		self::$buffer = [];

		// Cap at MAX_ENTRIES to prevent unbounded growth.
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}

		update_site_option( self::OPTION_KEY, $log );
	}

	/**
	 * Get log entries since a given index (for incremental polling).
	 *
	 * Flushes the buffer first so the poller sees the latest entries.
	 *
	 * @param int $since_index Return entries starting from this index.
	 * @return array{entries: array, index: int}
	 */
	public static function get( int $since_index = 0 ): array {
		$log   = get_site_option( self::OPTION_KEY, [] );
		$total = count( $log );

		return [
			'entries' => array_values( array_slice( $log, $since_index ) ),
			'index'   => $total,
		];
	}

	/**
	 * Delete all log entries and clear the buffer.
	 */
	public static function clear(): void {
		self::$buffer = [];
		delete_site_option( self::OPTION_KEY );
	}
}
