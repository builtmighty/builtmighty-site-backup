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

class Mighty_Backup_Log_Stream {

	private const OPTION_KEY      = 'bm_backup_live_log';
	private const PROGRESS_KEY    = 'bm_backup_live_progress';
	private const DEVCONTAINER_PROGRESS_KEY = 'bm_devcontainer_progress';
	private const MAX_ENTRIES     = 200;
	private const FLUSH_EVERY     = 10;

	/** @var array In-memory buffer of entries not yet persisted. */
	private static array $buffer = [];

	/**
	 * Clear any existing log entries. Call at backup start.
	 */
	public static function start(): void {
		self::$buffer = [];
		delete_site_option( self::OPTION_KEY );
		delete_site_option( self::PROGRESS_KEY );
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
		delete_site_option( self::PROGRESS_KEY );
	}

	/**
	 * Set the current step's live progress indicator.
	 *
	 * Stored in its own site option so the admin JS can render it as a single
	 * replaceable line — not appended to the scrolling log. ETA is in seconds;
	 * pass null when not yet computable (e.g. before the first part finishes).
	 */
	public static function set_progress( string $message, int $current, int $total, ?int $eta_seconds = null ): void {
		update_site_option( self::PROGRESS_KEY, [
			'message' => $message,
			'current' => $current,
			'total'   => $total,
			'percent' => $total > 0 ? (int) round( ( $current / $total ) * 100 ) : 0,
			'eta'     => $eta_seconds,
			'time'    => gmdate( 'H:i:s' ),
		] );
	}

	/**
	 * Read the current progress payload, or null when none is set.
	 *
	 * @return array{message:string,current:int,total:int,percent:int,eta:?int,time:string}|null
	 */
	public static function get_progress(): ?array {
		$p = get_site_option( self::PROGRESS_KEY );
		return is_array( $p ) ? $p : null;
	}

	/**
	 * Clear any current progress indicator. Call at step boundaries so a
	 * completed step's progress doesn't linger into the next one.
	 */
	public static function clear_progress(): void {
		delete_site_option( self::PROGRESS_KEY );
	}

	// ------------------------------------------------------------------
	// Devcontainer update progress — a separate channel from the backup
	// progress above, so a devcontainer update and a running backup never
	// clobber each other's progress payload.
	// ------------------------------------------------------------------

	/**
	 * Set the devcontainer update's live progress. Same payload shape as
	 * set_progress(), stored under its own site option.
	 */
	public static function set_devcontainer_progress( string $message, int $current, int $total, ?int $eta_seconds = null ): void {
		update_site_option( self::DEVCONTAINER_PROGRESS_KEY, [
			'message' => $message,
			'current' => $current,
			'total'   => $total,
			'percent' => $total > 0 ? (int) round( ( $current / $total ) * 100 ) : 0,
			'eta'     => $eta_seconds,
			'time'    => gmdate( 'H:i:s' ),
		] );
	}

	/**
	 * Read the devcontainer progress payload, or null when none is set.
	 *
	 * @return array{message:string,current:int,total:int,percent:int,eta:?int,time:string}|null
	 */
	public static function get_devcontainer_progress(): ?array {
		$p = get_site_option( self::DEVCONTAINER_PROGRESS_KEY );
		return is_array( $p ) ? $p : null;
	}

	/**
	 * Clear the devcontainer progress indicator. Call at the start of each
	 * update so a previous run's progress doesn't linger.
	 */
	public static function clear_devcontainer_progress(): void {
		delete_site_option( self::DEVCONTAINER_PROGRESS_KEY );
	}
}
