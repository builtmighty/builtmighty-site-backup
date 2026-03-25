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

$logger    = new Mighty_Backup_Logger();
$scheduler = new Mighty_Backup_Scheduler();
$last      = $logger->get_last_completed();
$next_run  = $scheduler->get_next_run();
$recent    = $logger->get_recent( 20 );
?>

<div class="wrap mb-backup-wrap">
    <h1><?php esc_html_e( 'Mighty Backup', 'mighty-backup' ); ?></h1>

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
                    </th>
                    <td>
                        <input type="number" id="bm_retention_count"
                               name="bm_backup_settings[retention_count]"
                               value="<?php echo esc_attr( $settings['retention_count'] ); ?>"
                               min="1" max="365" class="small-text" />
                        <p class="description"><?php esc_html_e( 'Number of backups to retain. Older backups are automatically deleted.', 'mighty-backup' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Database Export', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Streamlined Mode', 'mighty-backup' ); ?></th>
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

            <h2><?php esc_html_e( 'Notifications', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Email on Failure', 'mighty-backup' ); ?></th>
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

            <?php submit_button(); ?>
        </div><!-- /schedule -->

        <!-- Devcontainer Tab — GitHub Settings (inside form) -->
        <div class="mb-tab-panel" data-tab="devcontainer">
            <h2><?php esc_html_e( 'GitHub Repository', 'mighty-backup' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bm_github_owner"><?php esc_html_e( 'GitHub Owner', 'mighty-backup' ); ?></label>
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
                    </th>
                    <td>
                        <input type="password" id="bm_github_pat"
                               name="bm_backup_settings[github_pat]"
                               value="<?php echo ! empty( $settings['github_pat_enc'] ) ? '••••••••' : ''; ?>"
                               class="regular-text" autocomplete="new-password" />
                        <button type="button" class="mb-toggle-password" data-target="bm_github_pat"><?php esc_html_e( 'Show', 'mighty-backup' ); ?></button>
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
        <?php if ( ! empty( $recent ) ) : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
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
                    <?php foreach ( $recent as $entry ) : ?>
                        <tr>
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
                                    $word_count = str_word_count( $entry['error_message'] );
                                    if ( $word_count > 15 ) : ?>
                                        <span class="mb-error-short"><?php echo esc_html( wp_trim_words( $entry['error_message'], 15 ) ); ?></span>
                                        <span class="mb-error-full"><?php echo esc_html( $entry['error_message'] ); ?></span>
                                        <a href="#" class="mb-error-toggle"><?php esc_html_e( 'Show more', 'mighty-backup' ); ?></a>
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
        <?php else : ?>
            <p class="description"><?php esc_html_e( 'No backups yet — run your first backup above.', 'mighty-backup' ); ?></p>
        <?php endif; ?>
    </div><!-- /backup -->

    <!-- Codespace Tab -->
    <div class="mb-tab-panel" data-tab="codespace">
        <h2><?php esc_html_e( 'Codespace Bootstrap Key', 'mighty-backup' ); ?></h2>
        <p><?php esc_html_e( 'The bootstrap key encodes this site\'s URL and a secure API key into a single value. Add it as the ', 'mighty-backup' ); ?><code>BM_BOOTSTRAP_KEY</code><?php esc_html_e( ' Codespace secret and the migration pipeline will automatically retrieve all DigitalOcean credentials from this plugin — no need to manage them as separate secrets.', 'mighty-backup' ); ?></p>

        <?php
        $has_key       = ! empty( Mighty_Backup_Api_Endpoint::get_key() );
        $bootstrap_key = Mighty_Backup_Api_Endpoint::get_bootstrap_key();
        ?>

        <table class="form-table">
            <?php if ( $has_key ) : ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Bootstrap Key', 'mighty-backup' ); ?></th>
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
