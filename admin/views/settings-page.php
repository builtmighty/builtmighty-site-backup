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

$logger    = new BM_Backup_Logger();
$scheduler = new BM_Backup_Scheduler();
$last      = $logger->get_last_completed();
$next_run  = $scheduler->get_next_run();
$recent    = $logger->get_recent( 20 );
?>

<div class="wrap bm-backup-wrap">
    <h1><?php esc_html_e( 'BM Site Backup', 'builtmighty-site-backup' ); ?></h1>

    <?php if ( BM_Backup_Dev_Mode::is_dev_mode() ) : ?>
        <div class="notice notice-warning inline" style="margin-top:10px;">
            <p>
                <strong><?php esc_html_e( 'Dev Mode Active', 'builtmighty-site-backup' ); ?></strong> &mdash;
                <?php esc_html_e( 'Automatic backups are disabled because the site URL has changed from the original.', 'builtmighty-site-backup' ); ?>
            </p>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: original URL, 2: current URL */
                    esc_html__( 'Original: %1$s | Current: %2$s', 'builtmighty-site-backup' ),
                    '<code>' . esc_html( BM_Backup_Dev_Mode::get_live_url() ) . '</code>',
                    '<code>' . esc_html( network_site_url() ) . '</code>'
                );
                ?>
            </p>
            <p>
                <button type="button" id="bm-exit-dev-mode" class="button button-primary">
                    <?php esc_html_e( 'Enable Automatic Backups', 'builtmighty-site-backup' ); ?>
                </button>
                <span id="bm-dev-mode-result" class="bm-result-message" aria-live="polite"></span>
            </p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Settings saved.', 'builtmighty-site-backup' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $_GET['error'] ) ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html( urldecode( $_GET['error'] ) ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <nav class="nav-tab-wrapper">
        <a href="#storage" class="nav-tab nav-tab-active" data-tab="storage"><?php esc_html_e( 'Storage', 'builtmighty-site-backup' ); ?></a>
        <a href="#schedule" class="nav-tab" data-tab="schedule"><?php esc_html_e( 'Schedule', 'builtmighty-site-backup' ); ?></a>
        <a href="#backup" class="nav-tab" data-tab="backup"><?php esc_html_e( 'Backup', 'builtmighty-site-backup' ); ?></a>
        <a href="#codespace" class="nav-tab" data-tab="codespace"><?php esc_html_e( 'Codespace', 'builtmighty-site-backup' ); ?></a>
        <a href="#devcontainer" class="nav-tab" data-tab="devcontainer"><?php esc_html_e( 'Devcontainer', 'builtmighty-site-backup' ); ?></a>
    </nav>

    <form method="post" action="<?php echo esc_url( $action ); ?>" id="bm-settings-form">
        <?php
        if ( is_multisite() ) {
            wp_nonce_field( 'bm_backup_settings_group-options' );
        } else {
            settings_fields( 'bm_backup_settings_group' );
        }
        ?>

        <!-- Storage Tab -->
        <div class="bm-tab-panel active" data-tab="storage">
            <h2><?php esc_html_e( 'DigitalOcean Spaces', 'builtmighty-site-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_spaces_access_key"><?php esc_html_e( 'Access Key', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="bm_spaces_access_key"
                               name="bm_backup_settings[spaces_access_key]"
                               value="<?php echo ! empty( $settings['spaces_access_key'] ) ? '••••••••' : ''; ?>"
                               class="regular-text" autocomplete="new-password" />
                        <button type="button" class="bm-toggle-password" data-target="bm_spaces_access_key"><?php esc_html_e( 'Show', 'builtmighty-site-backup' ); ?></button>
                        <p class="description"><?php esc_html_e( 'Leave blank to keep the current key.', 'builtmighty-site-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_spaces_secret_key"><?php esc_html_e( 'Secret Key', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="bm_spaces_secret_key"
                               name="bm_backup_settings[spaces_secret_key]"
                               value="<?php echo ! empty( $settings['spaces_secret_key_enc'] ) ? '••••••••' : ''; ?>"
                               class="regular-text" autocomplete="new-password" />
                        <button type="button" class="bm-toggle-password" data-target="bm_spaces_secret_key"><?php esc_html_e( 'Show', 'builtmighty-site-backup' ); ?></button>
                        <p class="description"><?php esc_html_e( 'Leave blank to keep the current key.', 'builtmighty-site-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_spaces_endpoint"><?php esc_html_e( 'Endpoint', 'builtmighty-site-backup' ); ?></label>
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
                        <label for="bm_spaces_bucket"><?php esc_html_e( 'Bucket', 'builtmighty-site-backup' ); ?></label>
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
                        <label for="bm_client_path"><?php esc_html_e( 'GitHub Repository', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="bm_client_path"
                               name="bm_backup_settings[client_path]"
                               value="<?php echo esc_attr( $settings['client_path'] ); ?>"
                               class="regular-text"
                               placeholder="https://github.com/builtmighty/your-repo" />
                        <p class="description"><?php esc_html_e( 'Full GitHub repository URL or repo name. The repository slug is used as the Spaces path prefix.', 'builtmighty-site-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_hosting_provider"><?php esc_html_e( 'Hosting Provider', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <select id="bm_hosting_provider" name="bm_backup_settings[hosting_provider]">
                            <option value="" <?php selected( $settings['hosting_provider'], '' ); ?>>
                                <?php esc_html_e( '— Select provider —', 'builtmighty-site-backup' ); ?>
                            </option>
                            <option value="pressable" <?php selected( $settings['hosting_provider'], 'pressable' ); ?>>
                                <?php esc_html_e( 'Pressable', 'builtmighty-site-backup' ); ?>
                            </option>
                            <option value="generic" <?php selected( $settings['hosting_provider'], 'generic' ); ?>>
                                <?php esc_html_e( 'Generic (SFTP / cPanel)', 'builtmighty-site-backup' ); ?>
                            </option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Used by the Codespace bootstrap key to configure the migration pipeline.', 'builtmighty-site-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Test Connection', 'builtmighty-site-backup' ); ?></th>
                    <td>
                        <button type="button" id="bm-test-connection" class="button button-secondary">
                            <?php esc_html_e( 'Test Connection', 'builtmighty-site-backup' ); ?>
                        </button>
                        <span id="bm-test-result" class="bm-result-message" aria-live="polite"></span>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </div><!-- /storage -->

        <!-- Schedule Tab -->
        <div class="bm-tab-panel" data-tab="schedule">
            <h2><?php esc_html_e( 'Schedule', 'builtmighty-site-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_schedule_frequency"><?php esc_html_e( 'Frequency', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <select id="bm_schedule_frequency" name="bm_backup_settings[schedule_frequency]">
                            <option value="daily" <?php selected( $settings['schedule_frequency'], 'daily' ); ?>>
                                <?php esc_html_e( 'Daily', 'builtmighty-site-backup' ); ?>
                            </option>
                            <option value="twicedaily" <?php selected( $settings['schedule_frequency'], 'twicedaily' ); ?>>
                                <?php esc_html_e( 'Twice Daily', 'builtmighty-site-backup' ); ?>
                            </option>
                            <option value="weekly" <?php selected( $settings['schedule_frequency'], 'weekly' ); ?>>
                                <?php esc_html_e( 'Weekly', 'builtmighty-site-backup' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_schedule_time"><?php esc_html_e( 'Time', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="time" id="bm_schedule_time"
                               name="bm_backup_settings[schedule_time]"
                               value="<?php echo esc_attr( $settings['schedule_time'] ); ?>" />
                        <p class="description">
                            <?php esc_html_e( 'Server time. For reliable scheduling, set up a real system cron:', 'builtmighty-site-backup' ); ?>
                            <code>*/15 * * * * curl -s <?php echo esc_html( home_url( '/wp-cron.php' ) ); ?> > /dev/null 2>&1</code>
                        </p>
                    </td>
                </tr>
                <tr id="bm-schedule-day-row" class="<?php echo $settings['schedule_frequency'] === 'weekly' ? '' : 'bm-hidden'; ?>">
                    <th scope="row">
                        <label for="bm_schedule_day"><?php esc_html_e( 'Day of Week', 'builtmighty-site-backup' ); ?></label>
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

            <h2><?php esc_html_e( 'Retention', 'builtmighty-site-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_retention_count"><?php esc_html_e( 'Keep Last N Backups', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="bm_retention_count"
                               name="bm_backup_settings[retention_count]"
                               value="<?php echo esc_attr( $settings['retention_count'] ); ?>"
                               min="1" max="365" class="small-text" />
                        <p class="description"><?php esc_html_e( 'Number of backups to retain. Older backups are automatically deleted.', 'builtmighty-site-backup' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Database Export', 'builtmighty-site-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Streamlined Mode', 'builtmighty-site-backup' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="bm_backup_settings[streamlined_mode]" value="1"
                                <?php checked( $settings['streamlined_mode'] ); ?> />
                            <?php esc_html_e( 'Enable streamlined database export', 'builtmighty-site-backup' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Only exports the last 90 days of WooCommerce orders and their metadata. Log tables are exported as structure only (no data). Produces a smaller, faster backup ideal for dev environments.', 'builtmighty-site-backup' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'File Exclusions', 'builtmighty-site-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_extra_exclusions"><?php esc_html_e( 'Additional Exclude Patterns', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <textarea id="bm_extra_exclusions"
                                  name="bm_backup_settings[extra_exclusions]"
                                  rows="5" class="large-text code"><?php echo esc_textarea( $settings['extra_exclusions'] ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'One pattern per line. These are added to the defaults: wp-content/uploads, wp-content/cache, .git, node_modules, backup plugin directories (updraft, ai1wm-backups, etc.), and production drop-ins (object-cache.php, advanced-cache.php).', 'builtmighty-site-backup' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Notifications', 'builtmighty-site-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Email on Failure', 'builtmighty-site-backup' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="bm_backup_settings[notify_on_failure]" value="1"
                                <?php checked( $settings['notify_on_failure'] ); ?> />
                            <?php esc_html_e( 'Send email notification when a backup fails', 'builtmighty-site-backup' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_notification_email"><?php esc_html_e( 'Notification Email', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="email" id="bm_notification_email"
                               name="bm_backup_settings[notification_email]"
                               value="<?php echo esc_attr( $settings['notification_email'] ); ?>"
                               class="regular-text"
                               placeholder="<?php echo esc_attr( get_site_option( 'admin_email' ) ); ?>" />
                        <p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'builtmighty-site-backup' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </div><!-- /schedule -->

        <!-- Devcontainer Tab — GitHub Settings (inside form) -->
        <div class="bm-tab-panel" data-tab="devcontainer">
            <h2><?php esc_html_e( 'GitHub Repository', 'builtmighty-site-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_github_owner"><?php esc_html_e( 'GitHub Owner', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="bm_github_owner"
                               name="bm_backup_settings[github_owner]"
                               value="<?php echo esc_attr( $settings['github_owner'] ); ?>"
                               class="regular-text"
                               placeholder="builtmighty" />
                        <p class="description"><?php esc_html_e( 'GitHub organization or user that owns the repository. Defaults to "builtmighty" if left blank.', 'builtmighty-site-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_github_repo"><?php esc_html_e( 'Repository Slug', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="bm_github_repo"
                               name="bm_backup_settings[github_repo]"
                               value="<?php echo esc_attr( $settings['github_repo'] ); ?>"
                               class="regular-text"
                               placeholder="<?php echo esc_attr( $settings['client_path'] ?: 'client-repo' ); ?>" />
                        <p class="description"><?php esc_html_e( 'The repository name (slug only, not full URL). Defaults to the GitHub Repository setting from the Storage tab if left blank.', 'builtmighty-site-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bm_github_pat"><?php esc_html_e( 'Personal Access Token', 'builtmighty-site-backup' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="bm_github_pat"
                               name="bm_backup_settings[github_pat]"
                               value="<?php echo ! empty( $settings['github_pat_enc'] ) ? '••••••••' : ''; ?>"
                               class="regular-text" autocomplete="new-password" />
                        <button type="button" class="bm-toggle-password" data-target="bm_github_pat"><?php esc_html_e( 'Show', 'builtmighty-site-backup' ); ?></button>
                        <p class="description"><?php esc_html_e( 'Leave blank to keep the current token. Requires "repo" scope for private repositories.', 'builtmighty-site-backup' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </div><!-- /devcontainer (github settings) -->
    </form>

    <!-- Devcontainer Tab — Update Section (outside form, AJAX-driven) -->
    <div class="bm-tab-panel" data-tab="devcontainer" id="bm-devcontainer-update-wrap">
        <hr />
        <h2><?php esc_html_e( 'Devcontainer Update', 'builtmighty-site-backup' ); ?></h2>
        <p>
            <button type="button" id="bm-devcontainer-check" class="button button-secondary">
                <?php esc_html_e( 'Check Version', 'builtmighty-site-backup' ); ?>
            </button>
            <span id="bm-devcontainer-status" class="bm-result-message" aria-live="polite"></span>
        </p>
        <div id="bm-devcontainer-update-section" style="display:none;">
            <p id="bm-devcontainer-version-info"></p>
            <p>
                <button type="button" id="bm-devcontainer-update" class="button button-primary">
                    <?php esc_html_e( 'Install / Update Devcontainer', 'builtmighty-site-backup' ); ?>
                </button>
                <span id="bm-devcontainer-update-result" class="bm-result-message" aria-live="polite"></span>
            </p>
        </div>
    </div><!-- /devcontainer (update) -->

    <!-- Backup Tab -->
    <div class="bm-tab-panel" data-tab="backup">
        <h2><?php esc_html_e( 'Manual Backup', 'builtmighty-site-backup' ); ?></h2>
        <p>
            <?php if ( $last ) : ?>
                <strong><?php esc_html_e( 'Last backup:', 'builtmighty-site-backup' ); ?></strong>
                <?php echo esc_html( $last['completed_at'] ); ?>
                (<?php echo esc_html( $last['backup_type'] ); ?> / <?php echo esc_html( $last['trigger_type'] ); ?>)
                <?php if ( $last['db_file_size'] ) : ?>
                    &mdash; DB: <?php echo esc_html( size_format( $last['db_file_size'] ) ); ?>
                <?php endif; ?>
                <?php if ( $last['files_file_size'] ) : ?>
                    &mdash; Files: <?php echo esc_html( size_format( $last['files_file_size'] ) ); ?>
                <?php endif; ?>
            <?php else : ?>
                <em><?php esc_html_e( 'No backups have been run yet.', 'builtmighty-site-backup' ); ?></em>
            <?php endif; ?>
        </p>
        <?php if ( $next_run ) : ?>
            <p>
                <strong><?php esc_html_e( 'Next scheduled:', 'builtmighty-site-backup' ); ?></strong>
                <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $next_run ) . ' UTC' ); ?>
                <?php if ( BM_Backup_Dev_Mode::is_dev_mode() ) : ?>
                    <em>(<?php esc_html_e( 'paused — dev mode', 'builtmighty-site-backup' ); ?>)</em>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <p>
            <button type="button" id="bm-run-backup" class="button button-primary">
                <?php esc_html_e( 'Run Backup Now', 'builtmighty-site-backup' ); ?>
            </button>
            <button type="button" id="bm-cancel-backup" class="button" style="display:none;">
                <?php esc_html_e( 'Cancel Backup', 'builtmighty-site-backup' ); ?>
            </button>
            <span id="bm-backup-result" class="bm-result-message" aria-live="polite"></span>
        </p>

        <!-- Backup History -->
        <hr />
        <h2><?php esc_html_e( 'Backup History', 'builtmighty-site-backup' ); ?></h2>
        <?php if ( ! empty( $recent ) ) : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'builtmighty-site-backup' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'builtmighty-site-backup' ); ?></th>
                        <th><?php esc_html_e( 'Trigger', 'builtmighty-site-backup' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'builtmighty-site-backup' ); ?></th>
                        <th><?php esc_html_e( 'DB Size', 'builtmighty-site-backup' ); ?></th>
                        <th><?php esc_html_e( 'Files Size', 'builtmighty-site-backup' ); ?></th>
                        <th><?php esc_html_e( 'Error', 'builtmighty-site-backup' ); ?></th>
                        <th><?php esc_html_e( 'Download', 'builtmighty-site-backup' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $recent as $entry ) : ?>
                        <tr>
                            <td><?php echo esc_html( $entry['started_at'] ); ?></td>
                            <td><?php echo esc_html( $entry['backup_type'] ); ?></td>
                            <td><?php echo esc_html( $entry['trigger_type'] ); ?></td>
                            <td>
                                <span class="bm-status bm-status-<?php echo esc_attr( $entry['status'] ); ?>" role="status">
                                    <?php echo esc_html( ucfirst( $entry['status'] ) ); ?>
                                </span>
                            </td>
                            <td><?php echo $entry['db_file_size'] ? esc_html( size_format( $entry['db_file_size'] ) ) : '&mdash;'; ?></td>
                            <td><?php echo $entry['files_file_size'] ? esc_html( size_format( $entry['files_file_size'] ) ) : '&mdash;'; ?></td>
                            <td class="bm-error-cell">
                                <?php if ( $entry['error_message'] ) :
                                    $word_count = str_word_count( $entry['error_message'] );
                                    if ( $word_count > 15 ) : ?>
                                        <span class="bm-error-short"><?php echo esc_html( wp_trim_words( $entry['error_message'], 15 ) ); ?></span>
                                        <span class="bm-error-full"><?php echo esc_html( $entry['error_message'] ); ?></span>
                                        <a href="#" class="bm-error-toggle"><?php esc_html_e( 'Show more', 'builtmighty-site-backup' ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $entry['error_message'] ); ?>
                                    <?php endif; ?>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $entry['status'] === 'completed' ) : ?>
                                    <?php if ( ! empty( $entry['db_remote_key'] ) ) : ?>
                                        <a href="#" class="bm-download-link" data-key="<?php echo esc_attr( $entry['db_remote_key'] ); ?>" title="<?php echo esc_attr( $entry['db_remote_key'] ); ?>">DB</a>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $entry['files_remote_key'] ) ) : ?>
                                        <a href="#" class="bm-download-link" data-key="<?php echo esc_attr( $entry['files_remote_key'] ); ?>" title="<?php echo esc_attr( $entry['files_remote_key'] ); ?>">Files</a>
                                    <?php endif; ?>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="description"><?php esc_html_e( 'No backups yet — run your first backup above.', 'builtmighty-site-backup' ); ?></p>
        <?php endif; ?>
    </div><!-- /backup -->

    <!-- Codespace Tab -->
    <div class="bm-tab-panel" data-tab="codespace">
        <h2><?php esc_html_e( 'Codespace Bootstrap Key', 'builtmighty-site-backup' ); ?></h2>
        <p><?php esc_html_e( 'The bootstrap key encodes this site\'s URL and a secure API key into a single value. Add it as the ', 'builtmighty-site-backup' ); ?><code>BM_BOOTSTRAP_KEY</code><?php esc_html_e( ' Codespace secret and the migration pipeline will automatically retrieve all DigitalOcean credentials from this plugin — no need to manage them as separate secrets.', 'builtmighty-site-backup' ); ?></p>

        <?php
        $has_key       = ! empty( BM_Backup_Api_Endpoint::get_key() );
        $bootstrap_key = BM_Backup_Api_Endpoint::get_bootstrap_key();
        ?>

        <table class="form-table">
            <?php if ( $has_key ) : ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Bootstrap Key', 'builtmighty-site-backup' ); ?></th>
                <td>
                    <div class="bm-bootstrap-key-wrap">
                        <input type="text" id="bm-bootstrap-key" class="large-text code bm-bootstrap-key-input"
                               value="<?php echo esc_attr( $bootstrap_key ); ?>" readonly />
                        <button type="button" id="bm-copy-key" class="button bm-copy-btn" data-target="bm-bootstrap-key">
                            <?php esc_html_e( 'Copy', 'builtmighty-site-backup' ); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php esc_html_e( 'Add this as the', 'builtmighty-site-backup' ); ?>
                        <code>BM_BOOTSTRAP_KEY</code>
                        <?php esc_html_e( 'secret in your GitHub repository → Settings → Secrets and variables → Codespaces.', 'builtmighty-site-backup' ); ?>
                    </p>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th scope="row"><?php esc_html_e( $has_key ? 'Regenerate Key' : 'Generate Key', 'builtmighty-site-backup' ); ?></th>
                <td>
                    <button type="button" id="bm-generate-key" class="button button-secondary">
                        <?php esc_html_e( $has_key ? 'Regenerate Key' : 'Generate Key', 'builtmighty-site-backup' ); ?>
                    </button>
                    <span id="bm-key-result" class="bm-result-message"></span>
                    <?php if ( $has_key ) : ?>
                    <p class="description bm-key-warning">
                        <?php esc_html_e( '⚠ Regenerating invalidates the existing key. Update the BM_BOOTSTRAP_KEY secret in any Codespace that uses it.', 'builtmighty-site-backup' ); ?>
                    </p>
                    <?php else : ?>
                    <p class="description">
                        <?php esc_html_e( 'Generate a key to enable the one-secret Codespace setup.', 'builtmighty-site-backup' ); ?>
                    </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div><!-- /codespace -->

    <!-- Confirmation Modal -->
    <div id="bm-modal" class="bm-modal" style="display:none;" role="dialog" aria-modal="true">
        <div class="bm-modal-content">
            <p id="bm-modal-message"></p>
            <div class="bm-modal-actions">
                <button type="button" id="bm-modal-confirm" class="button button-primary"><?php esc_html_e( 'Confirm', 'builtmighty-site-backup' ); ?></button>
                <button type="button" id="bm-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'builtmighty-site-backup' ); ?></button>
            </div>
        </div>
    </div>
</div>
