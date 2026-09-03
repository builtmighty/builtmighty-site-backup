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
     * Object-name stem used when multisource is off — the historical layout,
     * `<client_path>/databases/backup-<timestamp>.sql.gz`.
     */
    const DEFAULT_OBJECT_STEM = 'backup';

    /**
     * Metadata for all writable settings keys.
     *
     * Keys are the CLI/user-facing names. Encrypted fields use the plaintext
     * name (e.g. 'spaces_secret_key') here; the actual storage key is in the
     * 'storage' field (e.g. 'spaces_secret_key_enc').
     */
    private const KEY_META = [
        'spaces_access_key'  => [ 'type' => 'string', 'encrypted' => false, 'storage' => 'spaces_access_key' ],
        'spaces_secret_key'  => [ 'type' => 'string', 'encrypted' => true,  'storage' => 'spaces_secret_key_enc' ],
        'spaces_endpoint'    => [ 'type' => 'string', 'encrypted' => false, 'storage' => 'spaces_endpoint' ],
        'spaces_bucket'      => [ 'type' => 'string', 'encrypted' => false, 'storage' => 'spaces_bucket' ],
        'client_path'        => [ 'type' => 'string', 'encrypted' => false, 'storage' => 'client_path' ],
        'hosting_provider'   => [ 'type' => 'enum',   'enum' => [ '', 'pressable', 'generic' ], 'storage' => 'hosting_provider' ],
        // Codespace environment. All five are overrides — blank means "detect
        // at request time" (see Mighty_Backup_Environment).
        'php_version'        => [ 'type' => 'php_version', 'encrypted' => false, 'storage' => 'php_version' ],
        'db_engine'          => [ 'type' => 'enum',   'enum' => [ '', 'mysql', 'mariadb' ], 'storage' => 'db_engine' ],
        'db_version'         => [ 'type' => 'string', 'encrypted' => false, 'storage' => 'db_version' ],
        'timezone'           => [ 'type' => 'timezone', 'encrypted' => false, 'storage' => 'timezone' ],
        'multisource'        => [ 'type' => 'bool',   'storage' => 'multisource' ],
        'multisource_name'   => [ 'type' => 'slug',   'encrypted' => false, 'storage' => 'multisource_name' ],
        'schedule_frequency' => [ 'type' => 'enum',   'enum' => [ 'daily', 'twicedaily', 'weekly' ], 'storage' => 'schedule_frequency' ],
        'schedule_time'      => [ 'type' => 'time',   'storage' => 'schedule_time' ],
        'schedule_day'       => [ 'type' => 'enum',   'enum' => [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ], 'storage' => 'schedule_day' ],
        'retention_count'    => [ 'type' => 'int',    'min' => 1, 'storage' => 'retention_count' ],
        'extra_exclusions'   => [ 'type' => 'text',   'storage' => 'extra_exclusions' ],
        'notify_on_failure'  => [ 'type' => 'bool',   'storage' => 'notify_on_failure' ],
        'notification_email' => [ 'type' => 'email',  'storage' => 'notification_email' ],
        'streamlined_mode'   => [ 'type' => 'bool',   'storage' => 'streamlined_mode' ],
        'db_chunk_seconds'           => [ 'type' => 'int', 'min' => 10,  'max' => 300,   'storage' => 'db_chunk_seconds' ],
        'db_large_table_threshold_mb' => [ 'type' => 'int', 'min' => 128, 'max' => 10240, 'storage' => 'db_large_table_threshold_mb' ],
        'github_owner'       => [ 'type' => 'string', 'encrypted' => false, 'storage' => 'github_owner' ],
        'github_repo'        => [ 'type' => 'string', 'encrypted' => false, 'storage' => 'github_repo' ],
        'github_pat'         => [ 'type' => 'string', 'encrypted' => true,  'storage' => 'github_pat_enc' ],
    ];

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
        add_action( 'wp_ajax_mighty_backup_bulk_delete', [ $this, 'ajax_bulk_delete' ] );
        add_action( 'wp_ajax_mighty_backup_dismiss_onboarding', [ $this, 'ajax_dismiss_onboarding' ] );
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
        $capability = is_multisite() ? 'manage_network_options' : 'manage_options';
        \add_menu_page(
            __( 'MightyBackup', 'mighty-backup' ),
            __( 'MightyBackup', 'mighty-backup' ),
            $capability,
            'mighty-backup',
            [ $this, 'render_page' ],
            'dashicons-cloud-saved',
            80
        );
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
        if ( 'toplevel_page_mighty-backup' !== $hook ) {
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
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'mighty_backup_nonce' ),
            'bulkDeleteNonce' => wp_create_nonce( 'mighty_backup_bulk_delete' ),
            'restUrl'         => esc_url_raw( rest_url() ),
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
        $settings         = $this->get_all();
        $tables_with_size = $this->get_tables_with_size();
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
        // Endpoint is stored as a bare host. Spaces_Client builds its endpoint
        // as 'https://' . $value, so a pasted "https://nyc3…" would otherwise
        // produce "https://https://nyc3…" and break every S3 call.
        $sanitized['spaces_endpoint']   = Mighty_Backup_Environment::normalize_endpoint(
            sanitize_text_field( $input['spaces_endpoint'] ?? '' )
        );
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

        // Codespace environment overrides. Each one is blank-means-detect, so an
        // unparseable value is discarded rather than persisted — /codespace-config
        // then reports whatever the live server actually is.
        $php_version              = sanitize_text_field( $input['php_version'] ?? '' );
        $sanitized['php_version'] = Mighty_Backup_Environment::is_valid_php_version( $php_version ) ? $php_version : '';

        $db_engine              = strtolower( sanitize_text_field( $input['db_engine'] ?? '' ) );
        $sanitized['db_engine'] = Mighty_Backup_Environment::is_valid_db_engine( $db_engine ) ? $db_engine : '';

        $sanitized['db_version'] = sanitize_text_field( $input['db_version'] ?? '' );

        $timezone              = sanitize_text_field( $input['timezone'] ?? '' );
        $sanitized['timezone'] = Mighty_Backup_Environment::is_valid_timezone( $timezone ) ? $timezone : '';

        // Multisource: several sites sharing one repo (and therefore one Spaces
        // prefix), told apart by the object-name stem. See get_object_stem().
        $sanitized['multisource']      = ! empty( $input['multisource'] );
        $sanitized['multisource_name'] = sanitize_title( $input['multisource_name'] ?? '' );

        // Email notifications.
        $sanitized['notify_on_failure'] = ! empty( $input['notify_on_failure'] );
        $sanitized['notification_email'] = sanitize_email( $input['notification_email'] ?? '' );

        // Database export mode.
        $sanitized['streamlined_mode'] = ! empty( $input['streamlined_mode'] );

        // Advanced DB-export tuning. Clamp into the meta-declared range so an
        // out-of-band value can't break the chunking heuristics.
        $chunk_meta = self::KEY_META['db_chunk_seconds'];
        $sanitized['db_chunk_seconds'] = max(
            $chunk_meta['min'],
            min( $chunk_meta['max'], (int) ( $input['db_chunk_seconds'] ?? 30 ) )
        );

        $thresh_meta = self::KEY_META['db_large_table_threshold_mb'];
        $sanitized['db_large_table_threshold_mb'] = max(
            $thresh_meta['min'],
            min( $thresh_meta['max'], (int) ( $input['db_large_table_threshold_mb'] ?? 1024 ) )
        );

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

        // Per-table exclusions.
        //
        // Form shape:
        //   table_list[]                  — every table rendered on the form (source of truth for "what was shown").
        //   table_structure[<table>] = 1  — present when the Structure box is checked.
        //   table_data[<table>] = 1       — present when the Data box is checked.
        //
        // Storing exclusions (rather than inclusions) means any table absent from
        // both arrays is implicitly fully exported — so new tables created after
        // a save are included automatically with no migration.
        $submitted_list  = (array) ( $input['table_list'] ?? [] );
        $submitted_struct = (array) ( $input['table_structure'] ?? [] );
        $submitted_data   = (array) ( $input['table_data'] ?? [] );

        $excluded       = [];
        $structure_only = [];
        if ( ! empty( $submitted_list ) ) {
            global $wpdb;
            $live_tables = (array) $wpdb->get_col( 'SHOW TABLES' );
            $live_lookup = array_fill_keys( $live_tables, true );

            foreach ( $submitted_list as $raw_table ) {
                $table = sanitize_key( (string) $raw_table );
                if ( $table === '' || ! isset( $live_lookup[ $table ] ) ) {
                    continue; // Unknown / stale entry — skip.
                }
                $has_struct = ! empty( $submitted_struct[ $table ] );
                $has_data   = ! empty( $submitted_data[ $table ] );

                if ( ! $has_struct ) {
                    $excluded[] = $table;
                } elseif ( ! $has_data ) {
                    $structure_only[] = $table;
                }
            }

            $sanitized['excluded_tables']       = array_values( array_unique( $excluded ) );
            $sanitized['structure_only_tables'] = array_values( array_unique( $structure_only ) );
        } else {
            // Form didn't submit the table section (e.g. multisite save before
            // the field was added) — preserve whatever was already saved.
            $sanitized['excluded_tables']       = (array) ( $current['excluded_tables'] ?? [] );
            $sanitized['structure_only_tables'] = (array) ( $current['structure_only_tables'] ?? [] );
        }

        return $sanitized;
    }

    /**
     * Return all database tables with their on-disk size in bytes.
     *
     * Used by the settings page to render the per-table inclusion list.
     * One information_schema query, no per-row overhead.
     *
     * @return array<string,int> Map of table_name => size_bytes, sorted by name.
     */
    public function get_tables_with_size(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT TABLE_NAME, (DATA_LENGTH + INDEX_LENGTH) AS size_bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = %s
             ORDER BY TABLE_NAME ASC",
            DB_NAME
        ), ARRAY_A );

        $out = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $out[ $row['TABLE_NAME'] ] = (int) $row['size_bytes'];
            }
        }
        return $out;
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
            'php_version'           => '',
            'db_engine'             => '',
            'db_version'            => '',
            'timezone'              => '',
            'multisource'           => false,
            'multisource_name'      => '',
            'streamlined_mode'      => false,
            'db_chunk_seconds'      => 30,
            'db_large_table_threshold_mb' => 1024,
            'github_owner'          => '',
            'github_repo'           => '',
            'github_pat_enc'        => '',
            'excluded_tables'       => [],
            'structure_only_tables' => [],
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
     * Return the list of writable settings keys (CLI/user-facing names).
     *
     * @return string[]
     */
    public function get_writable_keys(): array {
        return array_keys( self::KEY_META );
    }

    /**
     * Set a single setting value by its CLI/user-facing key.
     *
     * Handles type coercion, validation, and encryption for encrypted fields.
     *
     * @param string $key   User-facing key (see KEY_META).
     * @param string $value Raw string value from the CLI.
     * @throws \InvalidArgumentException If the key is unknown or the value fails validation.
     * @throws \RuntimeException         If encryption fails for an encrypted field.
     */
    public function set_value( string $key, string $value ): void {
        if ( ! isset( self::KEY_META[ $key ] ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'Unknown setting "%s". Valid keys: %s', $key, implode( ', ', $this->get_writable_keys() ) )
            );
        }

        $meta    = self::KEY_META[ $key ];
        $storage = $meta['storage'];
        $coerced = $this->coerce_value( $key, $value, $meta );

        // Encrypt if this is an encrypted field.
        if ( ! empty( $meta['encrypted'] ) ) {
            // An empty value would silently wipe the credential — reject it explicitly.
            if ( $coerced === '' ) {
                throw new \InvalidArgumentException(
                    sprintf( 'Refusing to store empty value for encrypted field "%s".', $key )
                );
            }
            $coerced = $this->encrypt( $coerced );
        }

        $all             = $this->get_all();
        $all[ $storage ] = $coerced;
        $this->save_all( $all );
    }

    /**
     * Get a single setting value by its CLI/user-facing key.
     *
     * For encrypted keys, returns the masked placeholder unless $reveal is true.
     *
     * @param string $key    User-facing key.
     * @param bool   $reveal If true, decrypt encrypted fields; otherwise mask them.
     * @return string
     * @throws \InvalidArgumentException If the key is unknown.
     */
    public function get_value( string $key, bool $reveal = false ): string {
        if ( ! isset( self::KEY_META[ $key ] ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'Unknown setting "%s".', $key )
            );
        }

        $meta = self::KEY_META[ $key ];

        if ( ! empty( $meta['encrypted'] ) ) {
            // Encrypted field — check storage, then either mask or decrypt.
            $encrypted = $this->get( $meta['storage'] );
            if ( empty( $encrypted ) ) {
                return '';
            }
            if ( ! $reveal ) {
                return '••••••••';
            }
            return $key === 'spaces_secret_key' ? $this->get_secret_key() : $this->get_github_pat();
        }

        $raw = $this->get( $meta['storage'] );

        // Render booleans consistently.
        if ( $meta['type'] === 'bool' ) {
            return $raw ? '1' : '0';
        }

        return (string) $raw;
    }

    /**
     * Return all writable settings as an associative array for CLI display.
     *
     * @param bool $reveal If true, decrypt encrypted fields; otherwise mask them.
     * @return array<string, string>
     */
    public function get_all_display( bool $reveal = false ): array {
        $out = [];
        foreach ( $this->get_writable_keys() as $key ) {
            $out[ $key ] = $this->get_value( $key, $reveal );
        }
        return $out;
    }

    /**
     * Coerce and validate a raw string value against the key's declared type.
     *
     * @param string $key   User-facing key (for error messages).
     * @param string $value Raw string value.
     * @param array  $meta  Metadata entry from KEY_META.
     * @return string|int|bool Coerced value ready for storage (or encryption).
     * @throws \InvalidArgumentException If the value is invalid for the declared type.
     */
    private function coerce_value( string $key, string $value, array $meta ) {
        $type = $meta['type'];

        switch ( $type ) {
            case 'bool':
                $truthy = [ '1', 'true', 'yes', 'on' ];
                $falsey = [ '0', 'false', 'no', 'off', '' ];
                $lower  = strtolower( trim( $value ) );
                if ( in_array( $lower, $truthy, true ) ) {
                    return true;
                }
                if ( in_array( $lower, $falsey, true ) ) {
                    return false;
                }
                throw new \InvalidArgumentException(
                    sprintf( 'Invalid boolean value "%s" for "%s". Use 1/0, true/false, yes/no, or on/off.', $value, $key )
                );

            case 'int':
                if ( ! preg_match( '/^-?\d+$/', trim( $value ) ) ) {
                    throw new \InvalidArgumentException(
                        sprintf( 'Invalid integer value "%s" for "%s".', $value, $key )
                    );
                }
                $int = (int) $value;
                if ( isset( $meta['min'] ) && $int < $meta['min'] ) {
                    throw new \InvalidArgumentException(
                        sprintf( 'Value for "%s" must be >= %d.', $key, $meta['min'] )
                    );
                }
                if ( isset( $meta['max'] ) && $int > $meta['max'] ) {
                    throw new \InvalidArgumentException(
                        sprintf( 'Value for "%s" must be <= %d.', $key, $meta['max'] )
                    );
                }
                return $int;

            case 'enum':
                if ( ! in_array( $value, $meta['enum'], true ) ) {
                    throw new \InvalidArgumentException(
                        sprintf( 'Invalid value "%s" for "%s". Allowed: %s', $value, $key, implode( ', ', $meta['enum'] ) )
                    );
                }
                return $value;

            case 'time':
                if ( ! preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
                    throw new \InvalidArgumentException(
                        sprintf( 'Invalid time "%s" for "%s". Expected HH:MM format.', $value, $key )
                    );
                }
                return $value;

            case 'email':
                $trimmed = trim( $value );
                if ( $trimmed !== '' && ! is_email( $trimmed ) ) {
                    throw new \InvalidArgumentException(
                        sprintf( 'Invalid email "%s" for "%s".', $value, $key )
                    );
                }
                return sanitize_email( $trimmed );

            case 'text':
                return sanitize_textarea_field( $value );

            case 'php_version':
                $trimmed = trim( $value );
                if ( $trimmed !== '' && ! Mighty_Backup_Environment::is_valid_php_version( $trimmed ) ) {
                    throw new \InvalidArgumentException(
                        sprintf( 'Invalid PHP version "%s" for "%s". Expected major.minor, e.g. 8.2.', $value, $key )
                    );
                }
                return $trimmed;

            case 'timezone':
                $trimmed = trim( $value );
                if ( $trimmed !== '' && ! Mighty_Backup_Environment::is_valid_timezone( $trimmed ) ) {
                    throw new \InvalidArgumentException(
                        sprintf( 'Invalid timezone "%s" for "%s". Expected an IANA zone name, e.g. America/Denver.', $value, $key )
                    );
                }
                return $trimmed;

            case 'slug':
                return sanitize_title( $value );

            case 'string':
            default:
                return sanitize_text_field( $value );
        }
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
     * The object-name stem for this site's backups — the single source of truth
     * for how remote keys are named.
     *
     * Single-source (the default) keeps the historical layout:
     *   <client_path>/databases/backup-<timestamp>.sql.gz
     *
     * Multisource means several sites share one repo, and therefore one Spaces
     * prefix, so the site's own name moves into the filename:
     *   <client_path>/databases/<name>-<timestamp>.sql.gz
     *
     * Retention scopes its listing to `<type>/<stem>-`, which is what keeps one
     * site from reaping a sibling's history (and what leaves pre-multisource
     * `backup-*` objects untouched).
     */
    public function get_object_stem(): string {
        if ( empty( $this->get( 'multisource' ) ) ) {
            return self::DEFAULT_OBJECT_STEM;
        }

        $name = sanitize_title( (string) $this->get( 'multisource_name' ) );
        if ( $name !== '' ) {
            return $name;
        }

        // No explicit name — derive one from the site host so two sites sharing
        // a repo still get distinct keys without any configuration.
        $host = (string) wp_parse_url( get_site_url(), PHP_URL_HOST );
        $host = sanitize_title( $host );

        return $host !== '' ? $host : self::DEFAULT_OBJECT_STEM;
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
     * Compute the state of each onboarding step.
     *
     * Each entry: [ key, label, target_tab, done (bool) ].
     */
    public function get_onboarding_steps(): array {
        $has_storage     = $this->is_configured();
        $has_api_key     = ! empty( Mighty_Backup_Api_Endpoint::get_key() );
        $last_push       = get_site_option( Mighty_Devcontainer_Manager::LAST_PUSH_OPTION );
        $has_pushed      = is_array( $last_push ) && ! empty( $last_push['timestamp'] );
        $logger          = new Mighty_Backup_Logger();
        $has_run_backup  = (bool) $logger->get_last_completed();

        return [
            [
                'key'    => 'storage',
                'label'  => __( 'Storage credentials', 'mighty-backup' ),
                'tab'    => 'storage',
                'done'   => $has_storage,
                'hint'   => __( 'Add your DigitalOcean Spaces access key, secret, endpoint, and bucket, then click Test Connection.', 'mighty-backup' ),
            ],
            [
                'key'    => 'schedule',
                'label'  => __( 'Schedule', 'mighty-backup' ),
                'tab'    => 'schedule',
                // Schedule has sane defaults, so we mark it complete once storage is set
                // — the wizard's job here is to direct attention to the tab, not gate setup.
                'done'   => $has_storage,
                'hint'   => __( 'Pick a frequency and time. Retention defaults to 7 backups — adjust if you want a longer tail.', 'mighty-backup' ),
            ],
            [
                'key'    => 'api_key',
                'label'  => __( 'Bootstrap key', 'mighty-backup' ),
                'tab'    => 'codespace',
                'done'   => $has_api_key,
                'hint'   => __( 'Generate the Codespace bootstrap key — one secret carries this site\'s URL + API key.', 'mighty-backup' ),
            ],
            [
                'key'    => 'github_push',
                'label'  => __( 'Push to GitHub', 'mighty-backup' ),
                'tab'    => 'devcontainer',
                'done'   => $has_pushed,
                'hint'   => __( 'Add your GitHub PAT on the Devcontainer tab, then push BM_BOOTSTRAP_KEY to the repo as a Codespaces secret.', 'mighty-backup' ),
            ],
            [
                'key'    => 'first_backup',
                'label'  => __( 'First backup', 'mighty-backup' ),
                'tab'    => 'backup',
                'done'   => $has_run_backup,
                'hint'   => __( 'Run a manual backup to confirm the full pipeline works end-to-end.', 'mighty-backup' ),
            ],
        ];
    }

    /**
     * Whether the onboarding wizard should render for the current user.
     *
     * Hidden when (a) the user dismissed it, (b) the plugin is fully configured
     * with at least one successful backup, or (c) we just have nothing to show.
     */
    public function needs_onboarding(): bool {
        $user_id = get_current_user_id();
        if ( $user_id && get_user_meta( $user_id, 'bm_onboarding_dismissed', true ) ) {
            return false;
        }
        $steps    = $this->get_onboarding_steps();
        $all_done = true;
        foreach ( $steps as $step ) {
            if ( empty( $step['done'] ) ) {
                $all_done = false;
                break;
            }
        }
        return ! $all_done;
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
                'The bundled AWS SDK could not be loaded, so this install is incomplete. '
                . 'Use the "Repair dependencies" button on the plugin warning notice, or reinstall '
                . 'the plugin from the latest release ZIP via Plugins → Add New → Upload Plugin.'
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
            Mighty_Backup_Error_Translator::send_ajax_error( $e );
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
                'The bundled AWS SDK could not be loaded, so this install is incomplete. '
                . 'Use the "Repair dependencies" button on the plugin warning notice, or reinstall '
                . 'the plugin from the latest release ZIP via Plugins → Add New → Upload Plugin.'
            );
        }

        if ( ! mighty_backup_has_action_scheduler() ) {
            wp_send_json_error(
                'Action Scheduler is not available. It ships with this plugin and is also provided by '
                . 'WooCommerce, so this install is incomplete — use the "Repair dependencies" button on '
                . 'the plugin warning notice, or reinstall from the latest release ZIP.'
            );
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
            Mighty_Backup_Error_Translator::send_ajax_error( $e );
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

        $manager = new Mighty_Backup_Manager();

        // Process the next pending backup action during each status poll.
        // This ensures the backup progresses even when WP-Cron or the
        // Action Scheduler async dispatcher aren't firing.
        $manager->process_next_action();

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

        // Under a multisource prefix the client path alone no longer isolates
        // this site, so also require the object-name stem. 'backup-' stays
        // allowed so history predating a multisource flip is still downloadable.
        $stem      = $this->get_object_stem();
        $basename  = basename( $key );
        $allowed   = array_unique( [ $stem . '-', self::DEFAULT_OBJECT_STEM . '-' ] );
        $stem_ok   = false;
        foreach ( $allowed as $candidate ) {
            if ( str_starts_with( $basename, $candidate ) ) {
                $stem_ok = true;
                break;
            }
        }
        if ( ! $stem_ok ) {
            wp_send_json_error( 'Invalid file key.' );
        }

        try {
            $client = new Mighty_Backup_Spaces_Client( $this );
            $url    = $client->get_presigned_url( $key );
            wp_send_json_success( [ 'url' => $url ] );
        } catch ( \Exception $e ) {
            Mighty_Backup_Error_Translator::send_ajax_error( 'Failed to generate download URL: ' . $e->getMessage() );
        }
    }

    /**
     * AJAX: Dismiss the onboarding wizard for the current user.
     */
    public function ajax_dismiss_onboarding(): void {
        check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $user_id = get_current_user_id();
        if ( $user_id ) {
            update_user_meta( $user_id, 'bm_onboarding_dismissed', 1 );
        }

        wp_send_json_success();
    }

    /**
     * AJAX: Bulk-delete backup history rows and their Spaces objects.
     *
     * Expects POST { log_ids: int[] }. Caps the per-request batch at 50;
     * the JS chunks larger selections. Running rows are filtered server-side
     * so a caller can't drop a backup that's still in progress.
     */
    public function ajax_bulk_delete(): void {
        check_ajax_referer( 'mighty_backup_bulk_delete', 'nonce' );

        if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        if ( ! $this->is_authorized_user() ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $raw_ids = isset( $_POST['log_ids'] ) ? (array) wp_unslash( $_POST['log_ids'] ) : [];
        $log_ids = array_slice(
            array_values( array_unique( array_filter( array_map( 'intval', $raw_ids ) ) ) ),
            0,
            50
        );

        if ( ! $log_ids ) {
            wp_send_json_error( 'No backups selected.' );
        }

        $logger  = new Mighty_Backup_Logger();
        $entries = $logger->get_by_ids( $log_ids );

        if ( ! $entries ) {
            wp_send_json_error( 'No deletable backups found in selection (running backups cannot be deleted).' );
        }

        // Collect Spaces keys to remove.
        $remote_keys = [];
        foreach ( $entries as $entry ) {
            if ( ! empty( $entry['db_remote_key'] ) ) {
                $remote_keys[] = $entry['db_remote_key'];
            }
            if ( ! empty( $entry['files_remote_key'] ) ) {
                $remote_keys[] = $entry['files_remote_key'];
            }
        }

        $remote_errors = [];
        if ( $remote_keys && mighty_backup_has_sdk() && $this->is_configured() ) {
            try {
                $client = new Mighty_Backup_Spaces_Client( $this );
                $client->delete_objects( $remote_keys );
            } catch ( \Throwable $e ) {
                // Capture the error but still delete the log rows — the user
                // explicitly asked to clear these from history.
                $remote_errors[] = $e->getMessage();
            }
        }

        $deleted = $logger->delete_by_ids( array_keys( $entries ) );

        wp_send_json_success( [
            'deleted_rows'    => $deleted,
            'deleted_objects' => count( $remote_keys ),
            'remote_errors'   => $remote_errors,
            'skipped_running' => count( $log_ids ) - count( $entries ),
        ] );
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
