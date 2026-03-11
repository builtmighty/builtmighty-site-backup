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

    <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Settings saved.', 'builtmighty-site-backup' ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( $action ); ?>">
        <?php
        if ( is_multisite() ) {
            wp_nonce_field( 'bm_backup_settings_group-options' );
        } else {
            settings_fields( 'bm_backup_settings_group' );
        }
        ?>

        <!-- DigitalOcean Spaces -->
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
                    <label for="bm_client_path"><?php esc_html_e( 'Client Path', 'builtmighty-site-backup' ); ?></label>
                </th>
                <td>
                    <input type="text" id="bm_client_path"
                           name="bm_backup_settings[client_path]"
                           value="<?php echo esc_attr( $settings['client_path'] ); ?>"
                           class="regular-text"
                           placeholder="clientname" />
                    <p class="description"><?php esc_html_e( 'Path prefix in the bucket (e.g., the client slug).', 'builtmighty-site-backup' ); ?></p>
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

        <!-- Schedule -->
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
            <tr id="bm-schedule-day-row" style="<?php echo $settings['schedule_frequency'] === 'weekly' ? '' : 'display:none;'; ?>">
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

        <!-- Retention -->
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

        <!-- File Exclusions -->
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
                        <?php esc_html_e( 'One pattern per line. These are added to the defaults: wp-content/uploads, wp-content/cache, .git, node_modules.', 'builtmighty-site-backup' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <!-- Notifications -->
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
    </form>

    <!-- Manual Backup -->
    <hr />
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
    <?php if ( ! empty( $recent ) ) : ?>
        <hr />
        <h2><?php esc_html_e( 'Backup History', 'builtmighty-site-backup' ); ?></h2>
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
                            <?php echo $entry['error_message'] ? esc_html( wp_trim_words( $entry['error_message'], 15 ) ) : '&mdash;'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Codespace Bootstrap Key -->
    <hr />
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
</div>
