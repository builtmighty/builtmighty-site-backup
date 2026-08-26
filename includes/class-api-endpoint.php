<?php
/**
 * REST API endpoint — returns Codespace configuration.
 *
 * GET /wp-json/mighty-backup/v1/codespace-config
 * Authorization: Bearer {api_key}
 *
 * Rate-limited to 10 requests per 60 seconds per IP.
 * Only accessible over HTTPS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mighty_Backup_Api_Endpoint {

	const ROUTE_NAMESPACE   = 'mighty-backup/v1';
	const ROUTE             = '/codespace-config';
	const ROUTE_CHECK       = '/check';
	const ROUTE_HEALTHCHECK = '/healthcheck';
	const API_KEY_OPTION    = 'bm_backup_api_key';
	const RATE_LIMIT        = 10; // max requests per window
	const RATE_WINDOW       = 60; // seconds

	/**
	 * Hook into WordPress.
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_request' ],
				'permission_callback' => '__return_true', // Auth handled inside callback.
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_CHECK,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_check' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_HEALTHCHECK,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_healthcheck' ],
				'permission_callback' => '__return_true', // Auth handled inside callback.
			]
		);
	}

	/**
	 * Validate HTTPS + Bearer token. Returns null on success, or a WP_Error
	 * suitable for direct return from a REST callback.
	 */
	private function authorize_bearer( WP_REST_Request $request ): ?WP_Error {
		if ( ! is_ssl() ) {
			return new WP_Error( 'https_required', 'HTTPS is required.', [ 'status' => 403 ] );
		}

		$auth_header = $request->get_header( 'Authorization' );
		if ( ! $auth_header || ! str_starts_with( $auth_header, 'Bearer ' ) ) {
			return new WP_Error( 'unauthorized', 'Missing or invalid Authorization header.', [ 'status' => 401 ] );
		}

		$provided_key = substr( $auth_header, 7 );
		$stored_key   = self::get_key();
		if ( empty( $stored_key ) || ! hash_equals( $stored_key, $provided_key ) ) {
			return new WP_Error( 'unauthorized', 'Invalid API key.', [ 'status' => 401 ] );
		}

		return null;
	}

	/**
	 * Handle the codespace-config request.
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// HTTPS only.
		if ( ! is_ssl() ) {
			return new WP_Error(
				'https_required',
				'HTTPS is required.',
				[ 'status' => 403 ]
			);
		}

		// Rate limiting — transient keyed by hashed IP.
		$ip        = $this->get_client_ip();
		$cache_key = 'bm_api_rl_' . md5( $ip );
		$count     = (int) get_transient( $cache_key );
		if ( $count >= self::RATE_LIMIT ) {
			return new WP_Error(
				'rate_limited',
				'Too many requests. Please wait a moment.',
				[ 'status' => 429 ]
			);
		}
		set_transient( $cache_key, $count + 1, self::RATE_WINDOW );

		// Bearer token authentication.
		$auth_header = $request->get_header( 'Authorization' );
		if ( ! $auth_header || ! str_starts_with( $auth_header, 'Bearer ' ) ) {
			return new WP_Error(
				'unauthorized',
				'Missing or invalid Authorization header.',
				[ 'status' => 401 ]
			);
		}

		$provided_key = substr( $auth_header, 7 );
		$stored_key   = self::get_key();
		if ( empty( $stored_key ) || ! hash_equals( $stored_key, $provided_key ) ) {
			return new WP_Error(
				'unauthorized',
				'Invalid API key.',
				[ 'status' => 401 ]
			);
		}

		// Build and return configuration payload.
		return new WP_REST_Response( self::build_config_payload(), 200 );
	}

	/**
	 * Build the /codespace-config payload.
	 *
	 * Every value is a string except `multisource`, which is a real JSON boolean.
	 * The five environment fields report the configured override when one is set
	 * and the detected value otherwise.
	 *
	 * Split out from handle_request() so the contract can be asserted in tests
	 * without standing up a REST request.
	 *
	 * @return array The flat config object.
	 */
	public static function build_config_payload(): array {
		$settings = new Mighty_Backup_Settings();
		$all      = $settings->get_all();

		$client_path = (string) ( $all['client_path'] ?? '' );
		$detected_db = Mighty_Backup_Environment::db_server();

		// Blank override => report what the live server actually is. An override
		// that is present but malformed is also ignored: the settings form and
		// the CLI both reject bad values, but the option can be written by other
		// means, and a garbage field here would break the bootstrap silently.
		$php_version = (string) ( $all['php_version'] ?? '' );
		if ( ! Mighty_Backup_Environment::is_valid_php_version( $php_version ) ) {
			$php_version = Mighty_Backup_Environment::php_version();
		}

		$db_engine = strtolower( (string) ( $all['db_engine'] ?? '' ) );
		if ( ! Mighty_Backup_Environment::is_valid_db_engine( $db_engine ) ) {
			$db_engine = $detected_db['engine'];
		}

		$db_version = (string) ( $all['db_version'] ?? '' ) ?: $detected_db['version'];

		$timezone = (string) ( $all['timezone'] ?? '' );
		if ( ! Mighty_Backup_Environment::is_valid_timezone( $timezone ) ) {
			$timezone = Mighty_Backup_Environment::timezone();
		}

		// Hosting provider defaults to 'generic' — only 'pressable' changes the
		// bootstrap's behaviour, so an unset value is the generic path.
		$provider = strtolower( trim( (string) ( $all['hosting_provider'] ?? '' ) ) );
		if ( $provider === '' ) {
			$provider = 'generic';
		}

		return [
			'do_spaces_key'      => (string) ( $all['spaces_access_key'] ?? '' ),
			'do_spaces_secret'   => (string) $settings->get_secret_key(),
			// Host only, no scheme — the bootstrap also expands this into
			// host_bucket = %(bucket)s.<endpoint>, which needs a bare host.
			'do_spaces_endpoint' => Mighty_Backup_Environment::normalize_endpoint( (string) ( $all['spaces_endpoint'] ?? '' ) ),
			'do_spaces_bucket'   => (string) ( $all['spaces_bucket'] ?? '' ),

			'client_path'        => $client_path,
			// Retained alongside client_path: bootstraps released before the
			// rename still read `repository`.
			'repository'         => $client_path,
			'hosting_provider'   => $provider,
			'remote_domain'      => (string) wp_parse_url( get_site_url(), PHP_URL_HOST ),

			'php_version'        => $php_version,
			'db_engine'          => $db_engine,
			'db_version'         => $db_version,

			'multisource'        => ! empty( $all['multisource'] ),
			'timezone'           => $timezone,
			'platform'           => 'wordpress',

			// The name this site's objects are keyed by under a shared prefix
			// ('backup' when multisource is off). Sibling sites share a repo, so
			// the bootstrap can't derive this from client_path.
			'source_name'        => $settings->get_object_stem(),
		];
	}

	/**
	 * Handle the health-check request — public, no auth required.
	 */
	public function handle_check( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( [
			'status'    => 'ok',
			'plugin'    => 'mighty-backup',
			'version'   => defined( 'MIGHTY_BACKUP_VERSION' ) ? MIGHTY_BACKUP_VERSION : 'unknown',
			'timestamp' => time(),
		], 200 );
	}

	/**
	 * Handle the authed healthcheck request. Returns the public /check fields
	 * plus a `placeholder_hash_corruption` summary so the codespace bootstrap
	 * (and external monitoring) can detect persisted wpdb hashes BEFORE they
	 * end up in a backup.
	 */
	public function handle_healthcheck( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$auth_error = $this->authorize_bearer( $request );
		if ( $auth_error ) {
			return $auth_error;
		}

		$counts = Mighty_Backup_Placeholder_Repair::count_corruption();
		$total  = 0;
		$sample = null;
		foreach ( $counts as $entry ) {
			$total += $entry['count'];
			if ( $sample === null && $entry['count'] > 0 ) {
				$sample = [ 'table' => $entry['table'], 'column' => $entry['column'] ];
			}
		}

		$payload = [
			'status'    => 'ok',
			'plugin'    => 'mighty-backup',
			'version'   => defined( 'MIGHTY_BACKUP_VERSION' ) ? MIGHTY_BACKUP_VERSION : 'unknown',
			'timestamp' => time(),
			'placeholder_hash_corruption' => [
				'count'         => $total,
				'sample_table'  => $sample['table']  ?? null,
				'sample_column' => $sample['column'] ?? null,
				'per_table'     => $counts,
			],
		];

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Generate a new random API key and persist it.
	 *
	 * Fires `mighty_backup_api_key_generated` after persisting so that
	 * downstream hooks (e.g., the devcontainer auto-push) can react to the
	 * fact that the bootstrap key has changed.
	 */
	public static function generate_key(): string {
		$key = bin2hex( random_bytes( 32 ) );
		update_site_option( self::API_KEY_OPTION, $key );
		do_action( 'mighty_backup_api_key_generated', $key );
		return $key;
	}

	/**
	 * Get the current API key (empty string if none exists).
	 */
	public static function get_key(): string {
		return (string) get_site_option( self::API_KEY_OPTION, '' );
	}

	/**
	 * Build the BM_BOOTSTRAP_KEY value: base64( site_url + ":" + api_key ).
	 */
	public static function get_bootstrap_key(): string {
		$key = self::get_key();
		if ( empty( $key ) ) {
			return '';
		}
		return base64_encode( get_site_url() . ':' . $key );
	}

	/**
	 * Get the client IP, respecting common proxy headers used in Codespaces.
	 */
	private function get_client_ip(): string {
		foreach ( [ 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// X-Forwarded-For may be a comma-separated list; take the first entry.
				return trim( explode( ',', $_SERVER[ $header ] )[0] );
			}
		}
		return 'unknown';
	}
}
