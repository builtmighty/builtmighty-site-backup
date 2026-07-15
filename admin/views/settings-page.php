<?php
/**
 * Settings page template.
 *
 * Variables available:
 *   $settings - array of all settings
 *   $action   - form action URL
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$logger             = new Mighty_Backup_Logger();
$scheduler          = new Mighty_Backup_Scheduler();
$last               = $logger->get_last_completed();
$next_run           = $scheduler->get_next_run();
$settings_obj       = new Mighty_Backup_Settings();
$show_onboarding    = $settings_obj->needs_onboarding();
$onboarding_steps   = $show_onboarding ? $settings_obj->get_onboarding_steps() : [];

$history_filters = [
    'status'   => isset( $_GET['bm_status'] )  ? sanitize_text_field( wp_unslash( $_GET['bm_status']  ) ) : '',
    'type'     => isset( $_GET['bm_type'] )    ? sanitize_text_field( wp_unslash( $_GET['bm_type']    ) ) : '',
    'trigger'  => isset( $_GET['bm_trigger'] ) ? sanitize_text_field( wp_unslash( $_GET['bm_trigger'] ) ) : '',
    'after'    => isset( $_GET['bm_after'] )   ? sanitize_text_field( wp_unslash( $_GET['bm_after']   ) ) : '',
    'before'   => isset( $_GET['bm_before'] )  ? sanitize_text_field( wp_unslash( $_GET['bm_before']  ) ) : '',
    'paged'    => isset( $_GET['bm_paged'] )   ? max( 1, (int) $_GET['bm_paged'] ) : 1,
    'per_page' => 20,
];
$history_has_filters = ( $history_filters['status'] || $history_filters['type'] || $history_filters['trigger'] || $history_filters['after'] || $history_filters['before'] );
$history_result      = $logger->query( $history_filters );
$recent              = $history_result['items'];
?>

<div class="wrap mb-backup-wrap">
    <h1><?php esc_html_e( 'MightyBackup', 'mighty-backup' ); ?></h1>

    <?php if ( Mighty_Backup_Dev_Mode::is_dev_mode() ) : ?>
        <div class="notice notice-warning inline" style="margin-top:10px;">
            <p>
                <strong><?php esc_html_e( 'Dev Mode Active', 'mighty-backup' ); ?></strong> &mdash;
                <?php esc_html_e( 'Automatic backups are disabled because the site URL has changed from the original.', 'mighty-backup' ); ?>
            </p>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: original URL, 2: current URL */
                    esc_html__( 'Original: %1$s | Current: %2$s', 'mighty-backup' ),
                    '<code>' . esc_html( Mighty_Backup_Dev_Mode::get_live_url() ) . '</code>',
                    '<code>' . esc_html( network_site_url() ) . '</code>'
                );
                ?>
            </p>
            <p>
                <button type="button" id="mb-exit-dev-mode" class="button button-primary">
                    <?php esc_html_e( 'Enable Automatic Backups', 'mighty-backup' ); ?>
                </button>
                <span id="mb-dev-mode-result" class="mb-result-message" aria-live="polite"></span>
            </p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Settings saved.', 'mighty-backup' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $_GET['error'] ) ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html( urldecode( $_GET['error'] ) ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $show_onboarding ) : ?>
        <?php include MIGHTY_BACKUP_DIR . 'admin/views/onboarding-wizard.php'; ?>
    <?php endif; ?>

    <!-- Tabs -->
    <nav class="nav-tab-wrapper">
        <a href="#storage" class="nav-tab nav-tab-active" data-tab="storage"><?php esc_html_e( 'Storage', 'mighty-backup' ); ?></a>
        <a href="#schedule" class="nav-tab" data-tab="schedule"><?php esc_html_e( 'Schedule', 'mighty-backup' ); ?></a>
        <a href="#backup" class="nav-tab" data-tab="backup"><?php esc_html_e( 'Backup', 'mighty-backup' ); ?></a>
        <a href="#codespace" class="nav-tab" data-tab="codespace"><?php esc_html_e( 'Codespace', 'mighty-backup' ); ?></a>
        <a href="#devcontainer" class="nav-tab" data-tab="devcontainer"><?php esc_html_e( 'Devcontainer', 'mighty-backup' ); ?></a>
    </nav>

    <form method="post" action="<?php echo esc_url( $action ); ?>" id="mb-settings-form">
        <?php
        if ( is_multisite() ) {
            wp_nonce_field( 'mighty_backup_settings_group-options' );
        } else {
            settings_fields( 'mighty_backup_settings_group' );
        }
        ?>

        <!-- Storage Tab -->
        <div class="mb-tab-panel active" data-tab="storage">
            <h2><?php esc_html_e( 'DigitalOcean Spaces', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_spaces_access_key"><?php esc_html_e( 'Access Key', 'mighty-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="bm_spaces_access_key"
                               name="bm_backup_settings[spaces_access_key]"
                               value="<?php echo ! empty( $settings['spaces_access_key'] ) ? '••••••••' : ''; ?>"
                               class="regular-text" autocomplete="new-password" />
                        <button type="button" class="mb-toggle-password" data-target="bm_spaces_access_key"><?php esc_html_e( 'Show', 'mighty-backup' ); ?></button>
                        <p class="description"><?php esc_html_e( 'Leave blank to keep the current key.', 'mighty-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_spaces_secret_key"><?php esc_html_e( 'Secret Key', 'mighty-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="bm_spaces_secret_key"
                               name="bm_backup_settings[spaces_secret_key]"
                               value="<?php echo ! empty( $settings['spaces_secret_key_enc'] ) ? '••••••••' : ''; ?>"
                               class="regular-text" autocomplete="new-password" />
                        <button type="button" class="mb-toggle-password" data-target="bm_spaces_secret_key"><?php esc_html_e( 'Show', 'mighty-backup' ); ?></button>
                        <p class="description"><?php esc_html_e( 'Leave blank to keep the current key.', 'mighty-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_spaces_endpoint"><?php esc_html_e( 'Endpoint', 'mighty-backup' ); ?></label>
                        <?php mighty_backup_help_icon( __( 'Region-specific host for DigitalOcean Spaces (e.g. nyc3.digitaloceanspaces.com, sfo3.digitaloceanspaces.com). Must match the region the bucket was created in.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <input type="text" id="bm_spaces_endpoint"
                               name="bm_backup_settings[spaces_endpoint]"
                               value="<?php echo esc_attr( $settings['spaces_endpoint'] ); ?>"
                               class="regular-text"
                               placeholder="nyc3.digitaloceanspaces.com" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_spaces_bucket"><?php esc_html_e( 'Bucket', 'mighty-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="bm_spaces_bucket"
                               name="bm_backup_settings[spaces_bucket]"
                               value="<?php echo esc_attr( $settings['spaces_bucket'] ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_client_path"><?php esc_html_e( 'GitHub Repository', 'mighty-backup' ); ?></label>
                        <?php mighty_backup_help_icon( __( 'The repo slug is used as the Spaces path prefix so each client\'s backups live in their own folder under the bucket. You can paste the full GitHub URL — the plugin extracts the slug automatically.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <input type="text" id="bm_client_path"
                               name="bm_backup_settings[client_path]"
                               value="<?php echo esc_attr( $settings['client_path'] ); ?>"
                               class="regular-text"
                               placeholder="https://github.com/builtmighty/your-repo" />
                        <p class="description"><?php esc_html_e( 'Full GitHub repository URL or repo name. The repository slug is used as the Spaces path prefix.', 'mighty-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_hosting_provider"><?php esc_html_e( 'Hosting Provider', 'mighty-backup' ); ?></label>
                        <?php mighty_backup_help_icon( __( 'Selects the migration path the Codespace bootstrap pipeline uses when pushing changes back to production. Pressable uses their REST API; Generic uses SFTP. Leave blank if you don\'t plan to push from Codespaces.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <select id="bm_hosting_provider" name="bm_backup_settings[hosting_provider]">
                            <option value="" <?php selected( $settings['hosting_provider'], '' ); ?>>
                                <?php esc_html_e( '— Select provider —', 'mighty-backup' ); ?>
                            </option>
                            <option value="pressable" <?php selected( $settings['hosting_provider'], 'pressable' ); ?>>
                                <?php esc_html_e( 'Pressable', 'mighty-backup' ); ?>
                            </option>
                            <option value="generic" <?php selected( $settings['hosting_provider'], 'generic' ); ?>>
                                <?php esc_html_e( 'Generic (SFTP / cPanel)', 'mighty-backup' ); ?>
                            </option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Used by the Codespace bootstrap key to configure the migration pipeline.', 'mighty-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Test Connection', 'mighty-backup' ); ?></th>
                    <td>
                        <button type="button" id="mb-test-connection" class="button button-secondary">
                            <?php esc_html_e( 'Test Connection', 'mighty-backup' ); ?>
                        </button>
                        <span id="mb-test-result" class="mb-result-message" aria-live="polite"></span>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </div><!-- /storage -->

        <!-- Schedule Tab -->
        <div class="mb-tab-panel" data-tab="schedule">
            <h2><?php esc_html_e( 'Schedule', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_schedule_frequency"><?php esc_html_e( 'Frequency', 'mighty-backup' ); ?></label>
                        <?php mighty_backup_help_icon( __( 'How often automatic backups run. WP-Cron drives the schedule, so the site must receive at least one request per scheduled window. For unattended sites, configure a real system cron — see the Time hint below.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <select id="bm_schedule_frequency" name="bm_backup_settings[schedule_frequency]">
                            <option value="daily" <?php selected( $settings['schedule_frequency'], 'daily' ); ?>>
                                <?php esc_html_e( 'Daily', 'mighty-backup' ); ?>
                            </option>
                            <option value="twicedaily" <?php selected( $settings['schedule_frequency'], 'twicedaily' ); ?>>
                                <?php esc_html_e( 'Twice Daily', 'mighty-backup' ); ?>
                            </option>
                            <option value="weekly" <?php selected( $settings['schedule_frequency'], 'weekly' ); ?>>
                                <?php esc_html_e( 'Weekly', 'mighty-backup' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_schedule_time"><?php esc_html_e( 'Time', 'mighty-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="time" id="bm_schedule_time"
                               name="bm_backup_settings[schedule_time]"
                               value="<?php echo esc_attr( $settings['schedule_time'] ); ?>" />
                        <p class="description">
                            <?php esc_html_e( 'Server time. For reliable scheduling, set up a real system cron:', 'mighty-backup' ); ?>
                            <code>*/15 * * * * curl -s <?php echo esc_html( home_url( '/wp-cron.php' ) ); ?> > /dev/null 2>&1</code>
                        </p>
                    </td>
                </tr>
                <tr id="bm-schedule-day-row" class="<?php echo $settings['schedule_frequency'] === 'weekly' ? '' : 'mb-hidden'; ?>">
                    <th scope="row">
                        <label for="bm_schedule_day"><?php esc_html_e( 'Day of Week', 'mighty-backup' ); ?></label>
                    </th>
                    <td>
                        <select id="bm_schedule_day" name="bm_backup_settings[schedule_day]">
                            <?php
                            $days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
                            foreach ( $days as $day ) :
                            ?>
                                <option value="<?php echo esc_attr( $day ); ?>" <?php selected( $settings['schedule_day'], $day ); ?>>
                                    <?php echo esc_html( ucfirst( $day ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Retention', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_retention_count"><?php esc_html_e( 'Keep Last N Backups', 'mighty-backup' ); ?></label>
                        <?php mighty_backup_help_icon( __( 'Retention runs after every successful backup AND once a day on its own cron. That means a streak of failing backups can\'t leave orphaned objects accumulating on Spaces — old backups are pruned regardless.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <input type="number" id="bm_retention_count"
                               name="bm_backup_settings[retention_count]"
                               value="<?php echo esc_attr( $settings['retention_count'] ); ?>"
                               min="1" max="365" class="small-text" />
                        <p class="description"><?php esc_html_e( 'Number of backups to retain. Older backups are automatically deleted.', 'mighty-backup' ); ?></p>
                        <?php
                        $last_retention = get_site_option( Mighty_Backup_Scheduler::LAST_RETENTION_RUN );
                        if ( is_array( $last_retention ) && ! empty( $last_retention['timestamp'] ) ) :
                            $when = human_time_diff( (int) $last_retention['timestamp'], time() );
                            if ( ! empty( $last_retention['error'] ) ) : ?>
                                <p class="description" style="color:#b32d2e;">
                                    <?php
                                    /* translators: 1: human-readable time difference, 2: error message */
                                    printf(
                                        esc_html__( 'Retention last attempted %1$s ago and failed: %2$s', 'mighty-backup' ),
                                        esc_html( $when ),
                                        esc_html( $last_retention['error'] )
                                    );
                                    ?>
                                </p>
                            <?php else : ?>
                                <p class="description">
                                    <?php
                                    $deleted = (int) ( $last_retention['databases_deleted'] ?? 0 )
                                             + (int) ( $last_retention['files_deleted'] ?? 0 );
                                    /* translators: 1: human-readable time difference, 2: number of files deleted */
                                    printf(
                                        esc_html( _n(
                                            'Retention last ran %1$s ago — deleted %2$d backup file.',
                                            'Retention last ran %1$s ago — deleted %2$d backup files.',
                                            $deleted,
                                            'mighty-backup'
                                        ) ),
                                        esc_html( $when ),
                                        $deleted
                                    );
                                    ?>
                                </p>
                            <?php endif;
                        endif;
                        ?>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Database Export', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Streamlined Mode', 'mighty-backup' ); ?>
                        <?php mighty_backup_help_icon( __( 'Skips data from log-shaped tables (actionscheduler_*, wc_session, etc.) and trims WooCommerce orders to the last 90 days. Schema is preserved. Useful for development environments where full history isn\'t needed — backup is much smaller and faster.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="bm_backup_settings[streamlined_mode]" value="1"
                                <?php checked( $settings['streamlined_mode'] ); ?> />
                            <?php esc_html_e( 'Enable streamlined database export', 'mighty-backup' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Only exports the last 90 days of WooCommerce orders and their metadata. Log tables are exported as structure only (no data). Produces a smaller, faster backup ideal for dev environments.', 'mighty-backup' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'File Exclusions', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_extra_exclusions"><?php esc_html_e( 'Additional Exclude Patterns', 'mighty-backup' ); ?></label>
                        <?php mighty_backup_help_icon( __( 'One path pattern per line, relative to the WordPress root. Defaults already exclude uploads, caches, .git, node_modules, and known backup-plugin directories. Add patterns here for client-specific paths you want left out of file archives.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <textarea id="bm_extra_exclusions"
                                  name="bm_backup_settings[extra_exclusions]"
                                  rows="5" class="large-text code"><?php echo esc_textarea( $settings['extra_exclusions'] ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'One pattern per line. These are added to the defaults: wp-content/uploads, wp-content/cache, .git, node_modules, backup plugin directories (updraft, ai1wm-backups, etc.), and production drop-ins (object-cache.php, advanced-cache.php).', 'mighty-backup' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Database Tables', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Per-Table Inclusion', 'mighty-backup' ); ?>
                        <?php mighty_backup_help_icon( __( 'Both checkboxes default to checked (full export). Uncheck "Data" to back up structure only. Uncheck both to skip the table entirely. New tables added later are included automatically.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <?php
                        $excluded_lookup = array_fill_keys( (array) ( $settings['excluded_tables'] ?? [] ), true );
                        $struct_lookup   = array_fill_keys( (array) ( $settings['structure_only_tables'] ?? [] ), true );
                        ?>
                        <div class="mb-table-exclusions">
                            <div class="mb-table-exclusions-header">
                                <span class="mb-table-exclusions-name-col"><?php esc_html_e( 'Table', 'mighty-backup' ); ?></span>
                                <span class="mb-table-exclusions-check-col"><?php esc_html_e( 'Structure', 'mighty-backup' ); ?></span>
                                <span class="mb-table-exclusions-check-col"><?php esc_html_e( 'Data', 'mighty-backup' ); ?></span>
                            </div>
                            <div class="mb-table-exclusions-list">
                                <?php if ( empty( $tables_with_size ) ) : ?>
                                    <div class="mb-table-exclusion-empty">
                                        <?php esc_html_e( 'No tables found.', 'mighty-backup' ); ?>
                                    </div>
                                <?php else : ?>
                                    <?php foreach ( $tables_with_size as $table => $size_bytes ) :
                                        $is_excluded       = isset( $excluded_lookup[ $table ] );
                                        $is_structure_only = isset( $struct_lookup[ $table ] );
                                        $structure_checked = ! $is_excluded;
                                        $data_checked      = ! $is_excluded && ! $is_structure_only;
                                        ?>
                                        <div class="mb-table-exclusion-item">
                                            <input type="hidden"
                                                   name="bm_backup_settings[table_list][]"
                                                   value="<?php echo esc_attr( $table ); ?>" />
                                            <span class="mb-table-name">
                                                <?php echo esc_html( $table ); ?>
                                                <span class="mb-table-size"><?php echo esc_html( size_format( $size_bytes ) ); ?></span>
                                            </span>
                                            <label class="mb-table-exclusion-check">
                                                <input type="checkbox"
                                                       class="mb-table-structure"
                                                       data-table="<?php echo esc_attr( $table ); ?>"
                                                       name="bm_backup_settings[table_structure][<?php echo esc_attr( $table ); ?>]"
                                                       value="1"
                                                       <?php checked( $structure_checked ); ?> />
                                            </label>
                                            <label class="mb-table-exclusion-check">
                                                <input type="checkbox"
                                                       class="mb-table-data"
                                                       data-table="<?php echo esc_attr( $table ); ?>"
                                                       name="bm_backup_settings[table_data][<?php echo esc_attr( $table ); ?>]"
                                                       value="1"
                                                       <?php checked( $data_checked ); ?>
                                                       <?php disabled( ! $structure_checked ); ?> />
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="description">
                            <?php esc_html_e( 'Both boxes checked = full export (default). Structure only = schema with no data. Both unchecked = table skipped.', 'mighty-backup' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Notifications', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Email on Failure', 'mighty-backup' ); ?>
                        <?php mighty_backup_help_icon( __( 'Email is the only notification channel — Mighty Backup deliberately does not surface a dashboard widget, admin bar dot, or Slack/Discord/Teams webhook. Use the site\'s configured mailer.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="bm_backup_settings[notify_on_failure]" value="1"
                                <?php checked( $settings['notify_on_failure'] ); ?> />
                            <?php esc_html_e( 'Send email notification when a backup fails', 'mighty-backup' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_notification_email"><?php esc_html_e( 'Notification Email', 'mighty-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="email" id="bm_notification_email"
                               name="bm_backup_settings[notification_email]"
                               value="<?php echo esc_attr( $settings['notification_email'] ); ?>"
                               class="regular-text"
                               placeholder="<?php echo esc_attr( get_site_option( 'admin_email' ) ); ?>" />
                        <p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'mighty-backup' ); ?></p>
                    </td>
                </tr>
            </table>

            <details class="mb-advanced-disclosure">
                <summary><?php esc_html_e( 'Advanced — large-site DB export tuning', 'mighty-backup' ); ?></summary>
                <p class="description">
                    <?php esc_html_e( 'Defaults work for most sites. Adjust these only when a backup fails on a single large table or you have unusually generous PHP time limits.', 'mighty-backup' ); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="bm_db_chunk_seconds"><?php esc_html_e( 'Chunk Seconds', 'mighty-backup' ); ?></label>
                            <?php mighty_backup_help_icon( __( 'Soft wall-time budget for each Action Scheduler chunk during PHP-based database export. The exporter yields between SQL batches once this limit is reached, then resumes mid-table in the next chunk. Lower for hosts with short PHP max_execution_time caps (Kinsta is typically 60s); raise on hosts with multi-minute caps to reduce chunk overhead on huge tables.', 'mighty-backup' ) ); ?>
                        </th>
                        <td>
                            <input type="number" id="bm_db_chunk_seconds"
                                   name="bm_backup_settings[db_chunk_seconds]"
                                   value="<?php echo esc_attr( (int) ( $settings['db_chunk_seconds'] ?? 30 ) ); ?>"
                                   min="10" max="300" step="5"
                                   class="small-text" />
                            <span class="description"><?php esc_html_e( 'seconds (10–300, default 30)', 'mighty-backup' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="bm_db_large_table_threshold_mb"><?php esc_html_e( 'Large-Table Threshold', 'mighty-backup' ); ?></label>
                            <?php mighty_backup_help_icon( __( 'On the mysqldump path, tables larger than this are exported across multiple chunks using --where ranges instead of a single monolithic invocation. Below the threshold, mysqldump runs once per table (faster on small/medium sites). Has no effect on the PHP-based export path, which always chunks.', 'mighty-backup' ) ); ?>
                        </th>
                        <td>
                            <input type="number" id="bm_db_large_table_threshold_mb"
                                   name="bm_backup_settings[db_large_table_threshold_mb]"
                                   value="<?php echo esc_attr( (int) ( $settings['db_large_table_threshold_mb'] ?? 1024 ) ); ?>"
                                   min="128" max="10240" step="64"
                                   class="small-text" />
                            <span class="description"><?php esc_html_e( 'MB (128–10240, default 1024 = 1 GB)', 'mighty-backup' ); ?></span>
                        </td>
                    </tr>
                </table>
            </details>

            <?php submit_button(); ?>
        </div><!-- /schedule -->

        <!-- Devcontainer Tab — GitHub Settings (inside form) -->
        <div class="mb-tab-panel" data-tab="devcontainer">
            <h2><?php esc_html_e( 'GitHub Repository', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_github_owner"><?php esc_html_e( 'GitHub Owner', 'mighty-backup' ); ?></label>
                        <?php mighty_backup_help_icon( __( 'The GitHub user or organization that owns the repo. Defaults to "builtmighty" if blank. Required for devcontainer install/update and the auto-push of BM_BOOTSTRAP_KEY.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <input type="text" id="bm_github_owner"
                               name="bm_backup_settings[github_owner]"
                               value="<?php echo esc_attr( $settings['github_owner'] ); ?>"
                               class="regular-text"
                               placeholder="builtmighty" />
                        <p class="description"><?php esc_html_e( 'GitHub organization or user that owns the repository. Defaults to "builtmighty" if left blank.', 'mighty-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_github_repo"><?php esc_html_e( 'Repository Slug', 'mighty-backup' ); ?></label>
                        <?php mighty_backup_help_icon( __( 'Just the repo name (not the full URL). Defaults to the GitHub Repository setting on the Storage tab if left blank — there\'s rarely a reason to set both.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <input type="text" id="bm_github_repo"
                               name="bm_backup_settings[github_repo]"
                               value="<?php echo esc_attr( $settings['github_repo'] ); ?>"
                               class="regular-text"
                               placeholder="<?php echo esc_attr( $settings['client_path'] ?: 'client-repo' ); ?>" />
                        <p class="description"><?php esc_html_e( 'The repository name (slug only, not full URL). Defaults to the GitHub Repository setting from the Storage tab if left blank.', 'mighty-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_github_pat"><?php esc_html_e( 'Personal Access Token', 'mighty-backup' ); ?></label>
                        <?php mighty_backup_help_icon( __( 'Required scopes: "repo" for the devcontainer PR, plus "codespaces:secrets" if you want this plugin to push BM_BOOTSTRAP_KEY automatically. Stored AES-256-CBC encrypted. Leave blank to keep the existing token.', 'mighty-backup' ) ); ?>
                    </th>
                    <td>
                        <input type="password" id="bm_github_pat"
                               name="bm_backup_settings[github_pat]"
                               value="<?php echo ! empty( $settings['github_pat_enc'] ) ? '••••••••' : ''; ?>"
                               class="regular-text" autocomplete="new-password" />
                        <p class="description"><?php esc_html_e( 'Leave blank to keep the current token. Requires "repo" scope for private repositories.', 'mighty-backup' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </div><!-- /devcontainer (github settings) -->
    </form>

    <!-- Devcontainer Tab — Update Section (outside form, AJAX-driven) -->
    <div class="mb-tab-panel" data-tab="devcontainer" id="mb-devcontainer-update-wrap">
        <hr />
        <h2><?php esc_html_e( 'Devcontainer Update', 'mighty-backup' ); ?></h2>
        <p>
            <button type="button" id="mb-devcontainer-check" class="button button-secondary">
                <?php esc_html_e( 'Check Version', 'mighty-backup' ); ?>
            </button>
            <span id="mb-devcontainer-status" class="mb-result-message" aria-live="polite"></span>
        </p>
        <div id="mb-devcontainer-update-section" style="display:none;">
            <p id="mb-devcontainer-version-info"></p>
            <p>
                <button type="button" id="mb-devcontainer-update" class="button button-primary">
                    <?php esc_html_e( 'Install / Update Devcontainer', 'mighty-backup' ); ?>
                </button>
                <span id="mb-devcontainer-update-result" class="mb-result-message" aria-live="polite"></span>
            </p>
            <div id="mb-devcontainer-progress-box" class="mb-progress-box" style="display:none;">
                <div class="mb-progress-bar-wrap" role="progressbar"
                     aria-label="<?php esc_attr_e( 'Devcontainer update progress', 'mighty-backup' ); ?>"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <div class="mb-progress-bar"></div>
                </div>
                <span class="mb-progress-text" aria-live="polite"></span>
            </div>
        </div>
    </div><!-- /devcontainer (update) -->

    <!-- Backup Tab -->
    <div class="mb-tab-panel" data-tab="backup">
        <h2><?php esc_html_e( 'Manual Backup', 'mighty-backup' ); ?></h2>
        <p>
            <?php if ( $last ) : ?>
                <strong><?php esc_html_e( 'Last backup:', 'mighty-backup' ); ?></strong>
                <?php echo esc_html( $last['completed_at'] ); ?>
                (<?php echo esc_html( $last['backup_type'] ); ?> / <?php echo esc_html( $last['trigger_type'] ); ?>)
                <?php if ( $last['db_file_size'] ) : ?>
                    &mdash; DB: <?php echo esc_html( size_format( $last['db_file_size'] ) ); ?>
                <?php endif; ?>
                <?php if ( $last['files_file_size'] ) : ?>
                    &mdash; Files: <?php echo esc_html( size_format( $last['files_file_size'] ) ); ?>
                <?php endif; ?>
            <?php else : ?>
                <em><?php esc_html_e( 'No backups have been run yet.', 'mighty-backup' ); ?></em>
            <?php endif; ?>
        </p>
        <?php if ( $next_run ) : ?>
            <p>
                <strong><?php esc_html_e( 'Next scheduled:', 'mighty-backup' ); ?></strong>
                <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $next_run ) . ' UTC' ); ?>
                <?php if ( Mighty_Backup_Dev_Mode::is_dev_mode() ) : ?>
                    <em>(<?php esc_html_e( 'paused — dev mode', 'mighty-backup' ); ?>)</em>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <p>
            <button type="button" id="mb-run-backup" class="button button-primary">
                <?php esc_html_e( 'Run Backup Now', 'mighty-backup' ); ?>
            </button>
            <button type="button" id="mb-cancel-backup" class="button" style="display:none;">
                <?php esc_html_e( 'Cancel Backup', 'mighty-backup' ); ?>
            </button>
            <span id="mb-backup-result" class="mb-result-message" aria-live="polite"></span>
        </p>

        <!-- Backup History -->
        <hr />
        <h2><?php esc_html_e( 'Backup History', 'mighty-backup' ); ?></h2>

        <form method="get" class="mb-history-filters">
            <input type="hidden" name="page" value="mighty-backup" />

            <select name="bm_status" aria-label="<?php esc_attr_e( 'Status', 'mighty-backup' ); ?>">
                <option value=""><?php esc_html_e( 'All statuses', 'mighty-backup' ); ?></option>
                <?php foreach ( [ 'completed', 'failed', 'running' ] as $opt ) : ?>
                    <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $history_filters['status'], $opt ); ?>>
                        <?php echo esc_html( ucfirst( $opt ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="bm_type" aria-label="<?php esc_attr_e( 'Type', 'mighty-backup' ); ?>">
                <option value=""><?php esc_html_e( 'All types', 'mighty-backup' ); ?></option>
                <?php foreach ( [ 'full', 'db', 'files' ] as $opt ) : ?>
                    <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $history_filters['type'], $opt ); ?>>
                        <?php echo esc_html( ucfirst( $opt ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="bm_trigger" aria-label="<?php esc_attr_e( 'Trigger', 'mighty-backup' ); ?>">
                <option value=""><?php esc_html_e( 'All triggers', 'mighty-backup' ); ?></option>
                <?php foreach ( [ 'scheduled', 'manual', 'cli' ] as $opt ) : ?>
                    <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $history_filters['trigger'], $opt ); ?>>
                        <?php echo esc_html( ucfirst( $opt ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="mb-history-date-label">
                <?php esc_html_e( 'From', 'mighty-backup' ); ?>
                <input type="date" name="bm_after" value="<?php echo esc_attr( $history_filters['after'] ); ?>" />
            </label>
            <label class="mb-history-date-label">
                <?php esc_html_e( 'To', 'mighty-backup' ); ?>
                <input type="date" name="bm_before" value="<?php echo esc_attr( $history_filters['before'] ); ?>" />
            </label>

            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'mighty-backup' ); ?></button>

            <?php if ( $history_has_filters ) : ?>
                <a href="<?php echo esc_url( remove_query_arg( [ 'bm_status', 'bm_type', 'bm_trigger', 'bm_after', 'bm_before', 'bm_paged' ] ) ); ?>" class="mb-history-clear">
                    <?php esc_html_e( 'Clear', 'mighty-backup' ); ?>
                </a>
            <?php endif; ?>

            <span class="mb-history-count">
                <?php
                /* translators: %d: total number of matching backup history entries */
                printf( esc_html__( '%d entries', 'mighty-backup' ), (int) $history_result['total'] );
                ?>
            </span>
        </form>

        <?php if ( ! empty( $recent ) ) : ?>
            <div class="mb-history-bulk tablenav top">
                <select id="mb-bulk-action" aria-label="<?php esc_attr_e( 'Bulk action', 'mighty-backup' ); ?>">
                    <option value=""><?php esc_html_e( 'Bulk actions', 'mighty-backup' ); ?></option>
                    <option value="delete"><?php esc_html_e( 'Delete selected (from history + Spaces)', 'mighty-backup' ); ?></option>
                    <option value="delete-failed"><?php esc_html_e( 'Delete all failed (this page)', 'mighty-backup' ); ?></option>
                </select>
                <button type="button" id="mb-bulk-apply" class="button"><?php esc_html_e( 'Apply', 'mighty-backup' ); ?></button>
                <span id="mb-bulk-result" class="mb-result-message" aria-live="polite"></span>
            </div>

            <table class="widefat striped mb-history-table">
                <thead>
                    <tr>
                        <th class="mb-history-check check-column">
                            <input type="checkbox" id="mb-history-check-all" aria-label="<?php esc_attr_e( 'Select all', 'mighty-backup' ); ?>" />
                        </th>
                        <th><?php esc_html_e( 'Date', 'mighty-backup' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'mighty-backup' ); ?></th>
                        <th><?php esc_html_e( 'Trigger', 'mighty-backup' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'mighty-backup' ); ?></th>
                        <th><?php esc_html_e( 'DB Size', 'mighty-backup' ); ?></th>
                        <th><?php esc_html_e( 'Files Size', 'mighty-backup' ); ?></th>
                        <th><?php esc_html_e( 'Error', 'mighty-backup' ); ?></th>
                        <th><?php esc_html_e( 'Download', 'mighty-backup' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $recent as $entry ) :
                        $is_running = ( $entry['status'] === 'running' );
                        ?>
                        <tr data-log-id="<?php echo esc_attr( (int) $entry['id'] ); ?>" data-status="<?php echo esc_attr( $entry['status'] ); ?>">
                            <td class="mb-history-check check-column">
                                <?php if ( ! $is_running ) : ?>
                                    <input type="checkbox" class="mb-history-row-check" value="<?php echo esc_attr( (int) $entry['id'] ); ?>" aria-label="<?php esc_attr_e( 'Select backup', 'mighty-backup' ); ?>" />
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $entry['started_at'] ); ?></td>
                            <td><?php echo esc_html( $entry['backup_type'] ); ?></td>
                            <td><?php echo esc_html( $entry['trigger_type'] ); ?></td>
                            <td>
                                <span class="mb-status mb-status-<?php echo esc_attr( $entry['status'] ); ?>" role="status">
                                    <?php echo esc_html( ucfirst( $entry['status'] ) ); ?>
                                </span>
                            </td>
                            <td><?php echo $entry['db_file_size'] ? esc_html( size_format( $entry['db_file_size'] ) ) : '&mdash;'; ?></td>
                            <td><?php echo $entry['files_file_size'] ? esc_html( size_format( $entry['files_file_size'] ) ) : '&mdash;'; ?></td>
                            <td class="mb-error-cell">
                                <?php if ( $entry['error_message'] ) :
                                    $translated = Mighty_Backup_Error_Translator::translate( $entry['error_message'] );
                                    $has_human  = $translated['human'] !== $translated['raw'];

                                    if ( $has_human ) : ?>
                                        <span class="mb-error-human"><?php echo esc_html( $translated['human'] ); ?></span>
                                        <?php if ( $translated['suggestion'] ) : ?>
                                            <span class="mb-error-suggestion"><?php echo esc_html( $translated['suggestion'] ); ?></span>
                                        <?php endif; ?>
                                        <span class="mb-error-raw"><code><?php echo esc_html( $translated['raw'] ); ?></code></span>
                                        <a href="#" class="mb-error-toggle" data-mode="raw"><?php esc_html_e( 'Show raw error', 'mighty-backup' ); ?></a>
                                    <?php else :
                                        $word_count = str_word_count( $translated['raw'] );
                                        if ( $word_count > 15 ) : ?>
                                            <span class="mb-error-short"><?php echo esc_html( wp_trim_words( $translated['raw'], 15 ) ); ?></span>
                                            <span class="mb-error-full"><?php echo esc_html( $translated['raw'] ); ?></span>
                                            <a href="#" class="mb-error-toggle"><?php esc_html_e( 'Show more', 'mighty-backup' ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( $translated['raw'] ); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $entry['status'] === 'completed' ) : ?>
                                    <?php if ( ! empty( $entry['db_remote_key'] ) ) : ?>
                                        <a href="#" class="mb-download-link" data-key="<?php echo esc_attr( $entry['db_remote_key'] ); ?>" title="<?php echo esc_attr( $entry['db_remote_key'] ); ?>">DB</a>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $entry['files_remote_key'] ) ) : ?>
                                        <a href="#" class="mb-download-link" data-key="<?php echo esc_attr( $entry['files_remote_key'] ); ?>" title="<?php echo esc_attr( $entry['files_remote_key'] ); ?>">Files</a>
                                    <?php endif; ?>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            if ( $history_result['total_pages'] > 1 ) :
                $page_links = paginate_links( [
                    'base'      => add_query_arg( 'bm_paged', '%#%' ),
                    'format'    => '',
                    'current'   => $history_result['paged'],
                    'total'     => $history_result['total_pages'],
                    'prev_text' => __( '&laquo; Prev', 'mighty-backup' ),
                    'next_text' => __( 'Next &raquo;', 'mighty-backup' ),
                    'add_args'  => false,
                ] );
                if ( $page_links ) :
                    ?>
                    <div class="mb-history-pagination tablenav">
                        <div class="tablenav-pages"><?php echo $page_links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — paginate_links() returns safe markup ?></div>
                    </div>
                    <?php
                endif;
            endif;
            ?>
        <?php else : ?>
            <p class="description">
                <?php if ( $history_has_filters ) : ?>
                    <?php esc_html_e( 'No backups match the current filters.', 'mighty-backup' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'No backups yet — run your first backup above.', 'mighty-backup' ); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div><!-- /backup -->

    <!-- Codespace Tab -->
    <div class="mb-tab-panel" data-tab="codespace">
        <h2><?php esc_html_e( 'API Health Check', 'mighty-backup' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Verify that the REST API endpoints are reachable.', 'mighty-backup' ); ?></p>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Endpoint Status', 'mighty-backup' ); ?></th>
                <td>
                    <button type="button" id="mb-check-api" class="button button-secondary">
                        <?php esc_html_e( 'Check API Health', 'mighty-backup' ); ?>
                    </button>
                    <span id="mb-api-check-result" class="mb-result-message" aria-live="polite"></span>
                </td>
            </tr>
        </table>
        <hr />

        <h2><?php esc_html_e( 'Codespace Bootstrap Key', 'mighty-backup' ); ?></h2>
        <p><?php esc_html_e( 'The bootstrap key encodes this site\'s URL and a secure API key into a single value. Add it as the ', 'mighty-backup' ); ?><code>BM_BOOTSTRAP_KEY</code><?php esc_html_e( ' Codespace secret and the migration pipeline will automatically retrieve all DigitalOcean credentials from this plugin — no need to manage them as separate secrets.', 'mighty-backup' ); ?></p>

        <?php
        $has_key       = ! empty( Mighty_Backup_Api_Endpoint::get_key() );
        $bootstrap_key = Mighty_Backup_Api_Endpoint::get_bootstrap_key();
        ?>

        <table class="form-table">
            <?php if ( $has_key ) : ?>
            <tr>
                <th scope="row">
                    <?php esc_html_e( 'Bootstrap Key', 'mighty-backup' ); ?>
                    <?php mighty_backup_help_icon( __( 'Base64-encoded "site_url:api_key" combo. The Codespace bootstrap pipeline decodes it to discover this site\'s REST endpoint and authenticate against /codespace-config — so a single secret carries everything the Codespace needs to fetch DO Spaces credentials.', 'mighty-backup' ) ); ?>
                </th>
                <td>
                    <div class="mb-bootstrap-key-wrap">
                        <input type="text" id="mb-bootstrap-key" class="large-text code mb-bootstrap-key-input"
                               value="<?php echo esc_attr( $bootstrap_key ); ?>" readonly />
                        <button type="button" id="mb-copy-key" class="button mb-copy-btn" data-target="mb-bootstrap-key">
                            <?php esc_html_e( 'Copy', 'mighty-backup' ); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php esc_html_e( 'Add this as the', 'mighty-backup' ); ?>
                        <code>BM_BOOTSTRAP_KEY</code>
                        <?php esc_html_e( 'secret in your GitHub repository → Settings → Secrets and variables → Codespaces.', 'mighty-backup' ); ?>
                    </p>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th scope="row"><?php esc_html_e( $has_key ? 'Regenerate Key' : 'Generate Key', 'mighty-backup' ); ?></th>
                <td>
                    <button type="button" id="mb-generate-key" class="button button-secondary">
                        <?php esc_html_e( $has_key ? 'Regenerate Key' : 'Generate Key', 'mighty-backup' ); ?>
                    </button>
                    <span id="mb-key-result" class="mb-result-message"></span>
                    <?php if ( $has_key ) : ?>
                    <p class="description mb-key-warning">
                        <?php esc_html_e( '⚠ Regenerating invalidates the existing key. Update the BM_BOOTSTRAP_KEY secret in any Codespace that uses it.', 'mighty-backup' ); ?>
                    </p>
                    <?php else : ?>
                    <p class="description">
                        <?php esc_html_e( 'Generate a key to enable the one-secret Codespace setup.', 'mighty-backup' ); ?>
                    </p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ( $has_key ) : ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Push to GitHub', 'mighty-backup' ); ?></th>
                <td>
                    <button type="button" id="mb-push-bootstrap-secret" class="button button-secondary">
                        <?php esc_html_e( 'Push as Codespaces Secret', 'mighty-backup' ); ?>
                    </button>
                    <span id="mb-push-secret-result" class="mb-result-message" aria-live="polite"></span>
                    <p id="mb-push-secret-status" class="description">
                        <?php
                        $last_push = get_site_option( Mighty_Devcontainer_Manager::LAST_PUSH_OPTION );
                        if ( is_array( $last_push ) && ! empty( $last_push['timestamp'] ) ) :
                            $when = human_time_diff( (int) $last_push['timestamp'], time() );
                            $repo = trim( ( $last_push['owner'] ?? '' ) . '/' . ( $last_push['repo'] ?? '' ), '/' );
                            /* translators: 1: GitHub owner/repo, 2: human-readable time difference */
                            printf(
                                esc_html__( 'Last synced to %1$s · %2$s ago', 'mighty-backup' ),
                                '<code>' . esc_html( $repo ) . '</code>',
                                esc_html( $when )
                            );
                        endif;
                        ?>
                    </p>
                    <p class="description">
                        <?php esc_html_e( 'Pushes this key directly to the configured GitHub repo as the', 'mighty-backup' ); ?>
                        <code>BM_BOOTSTRAP_KEY</code>
                        <?php esc_html_e( 'Codespaces secret. Requires a GitHub PAT with the Codespaces secrets write permission (configured in the Devcontainer tab).', 'mighty-backup' ); ?>
                    </p>
                </td>
            </tr>
            <?php endif; ?>
        </table>
    </div><!-- /codespace -->

    <!-- Confirmation Modal -->
    <div id="mb-modal" class="mb-modal" style="display:none;" role="dialog" aria-modal="true">
        <div class="mb-modal-content">
            <p id="mb-modal-message"></p>
            <div class="mb-modal-actions">
                <button type="button" id="mb-modal-confirm" class="button button-primary"><?php esc_html_e( 'Confirm', 'mighty-backup' ); ?></button>
                <button type="button" id="mb-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'mighty-backup' ); ?></button>
            </div>
        </div>
    </div>
</div>
