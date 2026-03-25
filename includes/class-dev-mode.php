<?php
/**
 * Dev mode detection — disables scheduled backups when the site URL changes.
 *
 * When a production database is copied to a staging or local environment the
 * stored "live" URL will no longer match the current site URL.  Scheduled
 * backups are paused automatically until an authorised user explicitly
 * re-enables them, preventing dev/staging environments from overwriting
 * production backups.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mighty_Backup_Dev_Mode {

	const LIVE_URL_OPTION = 'bm_backup_live_url';

	/**
	 * Hook into WordPress.
	 */
	public function init(): void {
		add_action( 'admin_notices', [ $this, 'show_admin_notice' ] );
		add_action( 'network_admin_notices', [ $this, 'show_admin_notice' ] );
		add_action( 'wp_ajax_mighty_backup_exit_dev_mode', [ $this, 'ajax_exit_dev_mode' ] );
	}

	/**
	 * Seed the live URL if it has not been set yet.
	 *
	 * Called on plugin activation and on every `plugins_loaded` so that
	 * existing installs updating to this version get seeded without needing
	 * to re-activate.
	 */
	public static function maybe_set_live_url(): void {
		if ( empty( get_site_option( self::LIVE_URL_OPTION, '' ) ) ) {
			update_site_option( self::LIVE_URL_OPTION, network_site_url() );
		}
	}

	/**
	 * Determine whether dev mode is active.
	 *
	 * Compares the stored live URL's host + path with the current site.
	 * Scheme differences (http ↔ https) are ignored.
	 *
	 * @return bool True when the site appears to be a clone/staging copy.
	 */
	public static function is_dev_mode(): bool {
		$stored = get_site_option( self::LIVE_URL_OPTION, '' );

		if ( empty( $stored ) ) {
			return false;
		}

		$current_url = network_site_url();

		$stored_host  = wp_parse_url( $stored, PHP_URL_HOST );
		$current_host = wp_parse_url( $current_url, PHP_URL_HOST );

		$stored_path  = wp_parse_url( $stored, PHP_URL_PATH ) ?: '/';
		$current_path = wp_parse_url( $current_url, PHP_URL_PATH ) ?: '/';

		$mismatch = ( $current_host !== $stored_host ) || ( $current_path !== $stored_path );

		/**
		 * Filter whether dev mode is active.
		 *
		 * @param bool $is_dev_mode True when a URL mismatch was detected.
		 */
		return (bool) apply_filters( 'mighty_backup_is_dev_mode', $mismatch );
	}

	/**
	 * Get the stored live URL for display purposes.
	 */
	public static function get_live_url(): string {
		return get_site_option( self::LIVE_URL_OPTION, '' );
	}

	/**
	 * Show a global admin notice when dev mode is active.
	 */
	public function show_admin_notice(): void {
		if ( ! self::is_dev_mode() ) {
			return;
		}

		/**
		 * Filter whether to display the dev mode admin notice.
		 *
		 * @param bool $show True to show the notice.
		 */
		if ( ! apply_filters( 'mighty_backup_dev_mode_show_notice', true ) ) {
			return;
		}

		if ( ! $this->is_authorized_user() ) {
			return;
		}

		$settings_url = is_multisite()
			? network_admin_url( 'settings.php?page=mighty-backup' )
			: admin_url( 'options-general.php?page=mighty-backup' );

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> &mdash; %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Mighty Backup: Dev Mode Active', 'mighty-backup' ),
			esc_html__( 'Automatic backups are disabled because the site URL has changed.', 'mighty-backup' ),
			esc_url( $settings_url ),
			esc_html__( 'Manage settings &rarr;', 'mighty-backup' )
		);
	}

	/**
	 * AJAX: Exit dev mode — update the stored URL and reschedule cron.
	 */
	public function ajax_exit_dev_mode(): void {
		check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		if ( ! $this->is_authorized_user() ) {
			wp_send_json_error( 'Unauthorized' );
		}

		update_site_option( self::LIVE_URL_OPTION, network_site_url() );

		$scheduler = new Mighty_Backup_Scheduler();
		$scheduler->reschedule();

		wp_send_json_success( [
			'message' => __( 'Automatic backups have been re-enabled.', 'mighty-backup' ),
		] );
	}

	/**
	 * Check whether the current user is authorised to manage the plugin.
	 */
	private function is_authorized_user(): bool {
		return mighty_backup_is_authorized_user();
	}
}
