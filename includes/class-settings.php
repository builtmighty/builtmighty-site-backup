<?php
/**
 * Plugin settings — admin page, credential storage, encryption.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BM_Backup_Settings {

    const OPTION_KEY = 'bm_backup_settings';

    /**
     * Check whether the current user is authorized to manage plugin settings.
     * Requires a matching email domain in addition to capability checks.
     */
    private function is_authorized_user(): bool {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return false;
        }
        $allowed_domains = apply_filters( 'bm_backup_admin_domains', [ 'builtmighty.com' ] );
        $email           = strtolower( $user->user_email );
        foreach ( $allowed_domains as $domain ) {
            if ( str_ends_with( $email, '@' . strtolower( $domain ) ) ) {
                return true;
            }
        }
        return false;
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
        add_action( 'wp_ajax_bm_backup_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_bm_backup_run_now', [ $this, 'ajax_run_now' ] );
        add_action( 'wp_ajax_bm_backup_check_status', [ $this, 'ajax_check_status' ] );
        add_action( 'wp_ajax_bm_backup_dismiss_status', [ $this, 'ajax_dismiss_status' ] );
        add_action( 'wp_ajax_bm_backup_cancel', [ $this, 'ajax_cancel' ] );
        add_action( 'wp_ajax_bm_backup_generate_api_key', [ $this, 'ajax_generate_api_key' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        if ( is_multisite() ) {
            add_action( 'network_admin_edit_bm_backup_save', [ $this, 'save_network_settings' ] );
        }
    }

    /**
     * Add the settings page to the admin menu.
     */
    public function add_menu_page(): void {
        if ( ! $this->is_authorized_user() ) {
            return;
        }
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';
        add_options_page(
            __( 'BM Site Backup', 'builtmighty-site-backup' ),
            __( 'BM Site Backup', 'builtmighty-site-backup' ),
            $capability,
            'bm-site-backup',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Register settings fields.
     */
    public function register_settings(): void {
        register_setting( 'bm_backup_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    /**
     * Enqueue admin assets on our settings page only.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'settings_page_bm-site-backup' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'bm-backup-admin',
            BM_BACKUP_URL . 'admin/assets/admin.css',
            [],
            BM_BACKUP_VERSION
        );
        wp_enqueue_script(
            'bm-backup-admin',
            BM_BACKUP_URL . 'admin/assets/admin.js',
            [ 'jquery' ],
            BM_BACKUP_VERSION,
            true
        );
        wp_localize_script( 'bm-backup-admin', 'bmBackup', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'bm_backup_nonce' ),
        ] );
    }

    /**
     * Render the settings page.
     */
    public function render_page(): void {
        if ( ! $this->is_authorized_user() ) {
            wp_die( __( 'You do not have permission to access this page.', 'builtmighty-site-backup' ) );
        }
        if ( is_multisite() ) {
            $action = network_admin_url( 'edit.php?action=bm_backup_save' );
        } else {
            $action = 'options.php';
        }
        $settings = $this->get_all();
        include BM_BACKUP_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Save settings on multisite network admin.
     */
    public function save_network_settings(): void {
        check_admin_referer( 'bm_backup_settings_group-options' );

        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( __( 'Unauthorized', 'builtmighty-site-backup' ) );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_die( __( 'Unauthorized', 'builtmighty-site-backup' ) );
        }

        $input = $_POST[ self::OPTION_KEY ] ?? [];
        $sanitized = $this->sanitize_settings( $input );
        $this->save_all( $sanitized );

        wp_safe_redirect( add_query_arg( [
            'page'    => 'bm-site-backup',
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
        $sanitized['client_path']       = sanitize_text_field( $input['client_path'] ?? '' );

        // Secret key — encrypt before storing. If field is empty, keep the old value.
        $raw_secret = $input['spaces_secret_key'] ?? '';
        if ( ! empty( $raw_secret ) && $raw_secret !== '••••••••' ) {
            $sanitized['spaces_secret_key_enc'] = $this->encrypt( $raw_secret );
        } else {
            $sanitized['spaces_secret_key_enc'] = $current['spaces_secret_key_enc'] ?? '';
        }

        // Schedule.
        $sanitized['schedule_frequency'] = sanitize_text_field( $input['schedule_frequency'] ?? 'daily' );
        $sanitized['schedule_time']      = sanitize_text_field( $input['schedule_time'] ?? '03:00' );
        $sanitized['schedule_day']       = sanitize_text_field( $input['schedule_day'] ?? 'monday' );

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

        return $sanitized;
    }

    /**
     * Get all settings.
     */
    public function get_all(): array {
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
        ];

        $saved = get_site_option( self::OPTION_KEY, [] );
        return wp_parse_args( $saved, $defaults );
    }

    /**
     * Save all settings.
     */
    public function save_all( array $settings ): void {
        update_site_option( self::OPTION_KEY, $settings );
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
        check_ajax_referer( 'bm_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! bm_backup_has_sdk() ) {
            wp_send_json_error(
                'AWS SDK not found. Please run "composer install" in the plugin directory or use a pre-built release.'
            );
        }

        if ( ! $this->is_configured() ) {
            wp_send_json_error( 'Please save your settings first.' );
        }

        try {
            $client = new BM_Backup_Spaces_Client( $this );
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
        check_ajax_referer( 'bm_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! bm_backup_has_sdk() ) {
            wp_send_json_error(
                'AWS SDK not found. Please run "composer install" in the plugin directory or use a pre-built release.'
            );
        }

        if ( ! bm_backup_has_action_scheduler() ) {
            wp_send_json_error( 'Action Scheduler not available. Run "composer install" in the plugin directory.' );
        }

        if ( ! $this->is_configured() ) {
            wp_send_json_error( 'Plugin is not configured. Please save your DO Spaces credentials first.' );
        }

        try {
            $manager = new BM_Backup_Manager();
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
        check_ajax_referer( 'bm_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $manager = new BM_Backup_Manager();
        wp_send_json_success( $manager->get_status() );
    }

    /**
     * AJAX: Dismiss/clear the backup status after completion or failure.
     */
    public function ajax_dismiss_status(): void {
        check_ajax_referer( 'bm_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $manager = new BM_Backup_Manager();
        $manager->clear_state();
        wp_send_json_success();
    }

    /**
     * AJAX: Cancel a running or pending backup.
     */
    public function ajax_cancel(): void {
        check_ajax_referer( 'bm_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $manager = new BM_Backup_Manager();
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
        check_ajax_referer( 'bm_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $key           = BM_Backup_Api_Endpoint::generate_key();
        $bootstrap_key = BM_Backup_Api_Endpoint::get_bootstrap_key();

        wp_send_json_success( [
            'bootstrap_key' => $bootstrap_key,
        ] );
    }

    /**
     * Encrypt a string using AES-256-CBC with wp_salt + optional BM_BACKUP_SECRET pepper.
     */
    private function encrypt( string $plaintext ): string {
        $pepper = defined( 'BM_BACKUP_SECRET' ) ? BM_BACKUP_SECRET : '';
        $key    = hash( 'sha256', wp_salt( 'auth' ) . $pepper, true );
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a string encrypted with encrypt().
     */
    private function decrypt( string $encoded ): string {
        $pepper = defined( 'BM_BACKUP_SECRET' ) ? BM_BACKUP_SECRET : '';
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
