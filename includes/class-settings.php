<?php
/**
 * Plugin settings — admin page, credential storage, encryption.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Settings {

    const OPTION_KEY = 'bm_backup_settings';

    /**
     * Check whether the current user is authorized to manage plugin settings.
     */
    private function is_authorized_user(): bool {
        return mighty_backup_is_authorized_user();
    }

    /**
     * Hook into WordPress.
     */
    public function init(): void {
        if ( is_multisite() ) {
            add_action( 'network_admin_menu', [ $this, 'add_menu_page' ] );
        } else {
            add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        }
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_mighty_backup_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_mighty_backup_run_now', [ $this, 'ajax_run_now' ] );
        add_action( 'wp_ajax_mighty_backup_check_status', [ $this, 'ajax_check_status' ] );
        add_action( 'wp_ajax_mighty_backup_dismiss_status', [ $this, 'ajax_dismiss_status' ] );
        add_action( 'wp_ajax_mighty_backup_cancel', [ $this, 'ajax_cancel' ] );
        add_action( 'wp_ajax_mighty_backup_generate_api_key', [ $this, 'ajax_generate_api_key' ] );
        add_action( 'wp_ajax_mighty_backup_download', [ $this, 'ajax_download' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        if ( is_multisite() ) {
            add_action( 'network_admin_edit_mighty_backup_save', [ $this, 'save_network_settings' ] );
        }
    }

    /**
     * Add the settings page to the admin menu.
     */
    public function add_menu_page(): void {
        if ( ! $this->is_authorized_user() ) {
            return;
        }
        if ( is_multisite() ) {
            add_submenu_page(
                'settings.php',
                __( 'Mighty Backup', 'mighty-backup' ),
                __( 'Mighty Backup', 'mighty-backup' ),
                'manage_network_options',
                'mighty-backup',
                [ $this, 'render_page' ]
            );
        } else {
            add_options_page(
                __( 'Mighty Backup', 'mighty-backup' ),
                __( 'Mighty Backup', 'mighty-backup' ),
                'manage_options',
                'mighty-backup',
                [ $this, 'render_page' ]
            );
        }
    }

    /**
     * Register settings fields.
     */
    public function register_settings(): void {
        if ( is_multisite() ) {
            return; // On multisite, settings are saved via save_network_settings().
        }
        register_setting( 'mighty_backup_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    /**
     * Enqueue admin assets on our settings page only.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'settings_page_mighty-backup' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'mighty-backup-admin',
            MIGHTY_BACKUP_URL . 'admin/assets/admin.css',
            [],
            MIGHTY_BACKUP_VERSION
        );
        wp_enqueue_script(
            'mighty-backup-admin',
            MIGHTY_BACKUP_URL . 'admin/assets/admin.js',
            [ 'jquery' ],
            MIGHTY_BACKUP_VERSION,
            true
        );
        wp_localize_script( 'mighty-backup-admin', 'mightyBackup', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'mighty_backup_nonce' ),
        ] );
    }

    /**
     * Render the settings page.
     */
    public function render_page(): void {
        if ( ! $this->is_authorized_user() ) {
            wp_die( __( 'You do not have permission to access this page.', 'mighty-backup' ) );
        }
        if ( is_multisite() ) {
            $action = network_admin_url( 'edit.php?action=mighty_backup_save' );
        } else {
            $action = 'options.php';
        }
        $settings = $this->get_all();
        include MIGHTY_BACKUP_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Save settings on multisite network admin.
     */
    public function save_network_settings(): void {
        check_admin_referer( 'mighty_backup_settings_group-options' );

        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( __( 'Unauthorized', 'mighty-backup' ) );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_die( __( 'Unauthorized', 'mighty-backup' ) );
        }

        $input = $_POST[ self::OPTION_KEY ] ?? [];

        try {
            $sanitized = $this->sanitize_settings( $input );
        } catch ( \RuntimeException $e ) {
            wp_safe_redirect( add_query_arg( [
                'page'  => 'mighty-backup',
                'error' => urlencode( $e->getMessage() ),
            ], network_admin_url( 'settings.php' ) ) );
            exit;
        }

        $this->save_all( $sanitized );

        wp_safe_redirect( add_query_arg( [
            'page'    => 'mighty-backup',
            'updated' => 'true',
        ], network_admin_url( 'settings.php' ) ) );
        exit;
    }

    /**
     * Sanitize settings before saving.
     */
    public function sanitize_settings( $input ): array {
        $current  = $this->get_all();
        $sanitized = [];

        // DO Spaces credentials.
        // Access key — write-only: keep existing if field is blank or the masked placeholder.
        $raw_access = $input['spaces_access_key'] ?? '';
        if ( ! empty( $raw_access ) && $raw_access !== '••••••••' ) {
            $sanitized['spaces_access_key'] = sanitize_text_field( $raw_access );
        } else {
            $sanitized['spaces_access_key'] = $current['spaces_access_key'] ?? '';
        }
        $sanitized['spaces_endpoint']   = sanitize_text_field( $input['spaces_endpoint'] ?? '' );
        $sanitized['spaces_bucket']     = sanitize_text_field( $input['spaces_bucket'] ?? '' );
        $sanitized['client_path']       = $this->extract_repo_slug( sanitize_text_field( $input['client_path'] ?? '' ) );

        // Secret key — encrypt before storing. If field is empty, keep the old value.
        $raw_secret = $input['spaces_secret_key'] ?? '';

        if ( ! empty( $raw_secret ) && $raw_secret !== '••••••••' ) {
            $encrypted = $this->encrypt( $raw_secret );
            // Never store empty — keep old value if encryption somehow fails.
            $sanitized['spaces_secret_key_enc'] = ! empty( $encrypted ) ? $encrypted : ( $current['spaces_secret_key_enc'] ?? '' );
        } else {
            $sanitized['spaces_secret_key_enc'] = $current['spaces_secret_key_enc'] ?? '';
        }

        // Schedule.
        $frequency = sanitize_text_field( $input['schedule_frequency'] ?? 'daily' );
        $sanitized['schedule_frequency'] = in_array( $frequency, [ 'daily', 'twicedaily', 'weekly' ], true ) ? $frequency : 'daily';

        $time = sanitize_text_field( $input['schedule_time'] ?? '03:00' );
        $sanitized['schedule_time'] = preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : '03:00';

        $day = sanitize_text_field( $input['schedule_day'] ?? 'monday' );
        $sanitized['schedule_day'] = in_array( $day, [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ], true ) ? $day : 'monday';

        // Retention.
        $sanitized['retention_count'] = max( 1, intval( $input['retention_count'] ?? 7 ) );

        // File exclusions (additional, one per line).
        $sanitized['extra_exclusions'] = sanitize_textarea_field( $input['extra_exclusions'] ?? '' );

        // Hosting provider (for Codespace bootstrap).
        $allowed_providers             = [ 'pressable', 'generic' ];
        $provider                      = sanitize_text_field( $input['hosting_provider'] ?? '' );
        $sanitized['hosting_provider'] = in_array( $provider, $allowed_providers, true ) ? $provider : '';

        // Email notifications.
        $sanitized['notify_on_failure'] = ! empty( $input['notify_on_failure'] );
        $sanitized['notification_email'] = sanitize_email( $input['notification_email'] ?? '' );

        // Database export mode.
        $sanitized['streamlined_mode'] = ! empty( $input['streamlined_mode'] );

        // GitHub (Devcontainer).
        $sanitized['github_owner'] = sanitize_text_field( $input['github_owner'] ?? '' );
        $sanitized['github_repo']  = sanitize_text_field( $input['github_repo'] ?? '' );

        $raw_pat = $input['github_pat'] ?? '';
        if ( ! empty( $raw_pat ) && $raw_pat !== '••••••••' ) {
            $encrypted = $this->encrypt( $raw_pat );
            $sanitized['github_pat_enc'] = ! empty( $encrypted ) ? $encrypted : ( $current['github_pat_enc'] ?? '' );
        } else {
            $sanitized['github_pat_enc'] = $current['github_pat_enc'] ?? '';
        }

        return $sanitized;
    }

    /**
     * Get all settings.
     */
    /** @var array|null Static cache shared across all instances. */
    private static ?array $cached_settings = null;

    public function get_all(): array {
        if ( self::$cached_settings !== null ) {
            return self::$cached_settings;
        }

        $defaults = [
            'spaces_access_key'     => '',
            'spaces_secret_key_enc' => '',
            'spaces_endpoint'       => '',
            'spaces_bucket'         => '',
            'client_path'           => '',
            'schedule_frequency'    => 'daily',
            'schedule_time'         => '03:00',
            'schedule_day'          => 'monday',
            'retention_count'       => 7,
            'extra_exclusions'      => '',
            'notify_on_failure'     => false,
            'notification_email'    => '',
            'hosting_provider'      => '',
            'streamlined_mode'      => false,
            'github_owner'          => '',
            'github_repo'           => '',
            'github_pat_enc'        => '',
        ];

        $saved = get_site_option( self::OPTION_KEY, [] );
        self::$cached_settings = wp_parse_args( $saved, $defaults );
        return self::$cached_settings;
    }

    /**
     * Save all settings.
     */
    public function save_all( array $settings ): void {
        update_site_option( self::OPTION_KEY, $settings );
        self::$cached_settings = null; // Invalidate cache.
    }

    /**
     * Get a single setting.
     */
    public function get( string $key, $default = '' ) {
        $all = $this->get_all();
        return $all[ $key ] ?? $default;
    }

    /**
     * Get the decrypted secret key.
     */
    public function get_secret_key(): string {
        $encrypted = $this->get( 'spaces_secret_key_enc' );
        if ( empty( $encrypted ) ) {
            return '';
        }
        return $this->decrypt( $encrypted );
    }

    /**
     * Get the decrypted GitHub Personal Access Token.
     */
    public function get_github_pat(): string {
        $encrypted = $this->get( 'github_pat_enc' );
        if ( empty( $encrypted ) ) {
            return '';
        }
        return $this->decrypt( $encrypted );
    }

    /**
     * Extract the repository slug from a GitHub URL or plain slug.
     *
     * Accepts formats like:
     *   https://github.com/builtmighty/protec
     *   https://github.com/builtmighty/protec.git
     *   github.com/builtmighty/protec
     *   protec
     */
    private function extract_repo_slug( string $value ): string {
        $value = trim( $value );
        if ( str_contains( $value, 'github.com/' ) ) {
            $path = wp_parse_url( $value, PHP_URL_PATH );
            if ( empty( $path ) ) {
                // Fallback: parse as URL with scheme.
                $path = wp_parse_url( 'https://' . $value, PHP_URL_PATH );
            }
            $segments = array_filter( explode( '/', trim( $path ?? '', '/' ) ) );
            $slug     = end( $segments );
            $slug = $slug ?: $value;
            return str_ends_with( $slug, '.git' ) ? substr( $slug, 0, -4 ) : $slug;
        }
        return str_ends_with( $value, '.git' ) ? substr( $value, 0, -4 ) : $value;
    }

    /**
     * Check if the plugin is configured (has required credentials).
     */
    public function is_configured(): bool {
        return ! empty( $this->get( 'spaces_access_key' ) )
            && ! empty( $this->get( 'spaces_secret_key_enc' ) )
            && ! empty( $this->get( 'spaces_endpoint' ) )
            && ! empty( $this->get( 'spaces_bucket' ) )
            && ! empty( $this->get( 'client_path' ) );
    }

    /**
     * AJAX: Test DO Spaces connection.
     */
    public function ajax_test_connection(): void {
        check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! mighty_backup_has_sdk() ) {
            wp_send_json_error(
                'AWS SDK not found. Please run "composer install" in the plugin directory or use a pre-built release.'
            );
        }

        if ( ! $this->is_configured() ) {
            wp_send_json_error( 'Please save your settings first.' );
        }

        try {
            $client = new Mighty_Backup_Spaces_Client( $this );
            $result = $client->test_connection();
            wp_send_json_success( $result );
        } catch ( \Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * AJAX: Run backup now — schedules the action chain and returns immediately.
     */
    public function ajax_run_now(): void {
        check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! mighty_backup_has_sdk() ) {
            wp_send_json_error(
                'AWS SDK not found. Please run "composer install" in the plugin directory or use a pre-built release.'
            );
        }

        if ( ! mighty_backup_has_action_scheduler() ) {
            wp_send_json_error( 'Action Scheduler not available. Run "composer install" in the plugin directory.' );
        }

        if ( ! $this->is_configured() ) {
            wp_send_json_error( 'Plugin is not configured. Please save your DO Spaces credentials first.' );
        }

        try {
            $manager = new Mighty_Backup_Manager();
            $manager->schedule( 'full', 'manual' );
            wp_send_json_success( [
                'message' => 'Backup scheduled. It will begin processing in the background.',
            ] );
        } catch ( \Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * AJAX: Check backup status — polled by the admin JS.
     */
    public function ajax_check_status(): void {
        check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $manager   = new Mighty_Backup_Manager();
        $log_since = isset( $_POST['since'] ) ? absint( $_POST['since'] ) : 0;
        wp_send_json_success( $manager->get_status( $log_since ) );
    }

    /**
     * AJAX: Dismiss/clear the backup status after completion or failure.
     */
    public function ajax_dismiss_status(): void {
        check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $manager = new Mighty_Backup_Manager();
        $manager->clear_state();
        Mighty_Backup_Log_Stream::clear();
        wp_send_json_success();
    }

    /**
     * AJAX: Cancel a running or pending backup.
     */
    public function ajax_cancel(): void {
        check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $manager = new Mighty_Backup_Manager();
        if ( $manager->cancel() ) {
            wp_send_json_success( [ 'message' => 'Backup cancelled.' ] );
        } else {
            wp_send_json_error( 'No active backup to cancel.' );
        }
    }

    /**
     * AJAX: Generate (or regenerate) the Codespace API key.
     */
    public function ajax_generate_api_key(): void {
        check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $key           = Mighty_Backup_Api_Endpoint::generate_key();
        $bootstrap_key = Mighty_Backup_Api_Endpoint::get_bootstrap_key();

        wp_send_json_success( [
            'bootstrap_key' => $bootstrap_key,
        ] );
    }

    /**
     * AJAX: Generate a temporary pre-signed download URL for a backup file.
     */
    public function ajax_download(): void {
        check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! mighty_backup_has_sdk() ) {
            wp_send_json_error( 'AWS SDK not available.' );
        }

        $key = sanitize_text_field( $_POST['key'] ?? '' );
        if ( empty( $key ) ) {
            wp_send_json_error( 'Missing file key.' );
        }

        // Validate that the key belongs to this client's path to prevent path traversal.
        $client_path = rtrim( $this->get( 'client_path' ), '/' );
        if ( empty( $client_path ) ) {
            wp_send_json_error( 'Client path not configured.' );
        }
        if ( ! str_starts_with( $key, $client_path . '/' ) ) {
            wp_send_json_error( 'Invalid file key.' );
        }

        try {
            $client = new Mighty_Backup_Spaces_Client( $this );
            $url    = $client->get_presigned_url( $key );
            wp_send_json_success( [ 'url' => $url ] );
        } catch ( \Exception $e ) {
            wp_send_json_error( 'Failed to generate download URL: ' . $e->getMessage() );
        }
    }

    /**
     * Encrypt a string using AES-256-CBC with wp_salt + optional MIGHTY_BACKUP_SECRET pepper.
     */
    private function encrypt( string $plaintext ): string {
        $pepper = defined( 'MIGHTY_BACKUP_SECRET' ) ? MIGHTY_BACKUP_SECRET : ( defined( 'BM_BACKUP_SECRET' ) ? BM_BACKUP_SECRET : '' );
        $key    = hash( 'sha256', wp_salt( 'auth' ) . $pepper, true );
        $iv = random_bytes( 16 );
        $cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        if ( $cipher === false ) {
            throw new \RuntimeException( 'openssl_encrypt() failed: ' . openssl_error_string() );
        }
        return base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a string encrypted with encrypt().
     */
    private function decrypt( string $encoded ): string {
        $pepper = defined( 'MIGHTY_BACKUP_SECRET' ) ? MIGHTY_BACKUP_SECRET : ( defined( 'BM_BACKUP_SECRET' ) ? BM_BACKUP_SECRET : '' );
        $key    = hash( 'sha256', wp_salt( 'auth' ) . $pepper, true );
        $data   = base64_decode( $encoded );
        if ( strlen( $data ) < 17 ) {
            return '';
        }
        $iv     = substr( $data, 0, 16 );
        $cipher = substr( $data, 16 );
        $result = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return $result !== false ? $result : '';
    }
}
