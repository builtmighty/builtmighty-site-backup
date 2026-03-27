<?php
/**
 * Plugin Name: Mighty Backup
 * Plugin URI: https://github.com/builtmighty/builtmighty-site-backup
 * Description: Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.
 * Version: 2.3.0
 * Author: Built Mighty
 * Author URI: https://builtmighty.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mighty-backup
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MIGHTY_BACKUP_VERSION', '2.3.0' );
define( 'MIGHTY_BACKUP_FILE', __FILE__ );
define( 'MIGHTY_BACKUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MIGHTY_BACKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'MIGHTY_BACKUP_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoload (AWS SDK + Action Scheduler).
if ( file_exists( MIGHTY_BACKUP_DIR . 'vendor/autoload.php' ) ) {
    require_once MIGHTY_BACKUP_DIR . 'vendor/autoload.php';
}

// Load Action Scheduler if not already loaded by WooCommerce or another plugin.
if ( ! function_exists( 'as_schedule_single_action' ) ) {
    $as_path = MIGHTY_BACKUP_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
    if ( file_exists( $as_path ) ) {
        require_once $as_path;
    }
}

/**
 * Check if the AWS SDK is available.
 */
function mighty_backup_has_sdk(): bool {
    return class_exists( 'Aws\S3\S3Client' );
}

/**
 * Check if Action Scheduler is available.
 */
function mighty_backup_has_action_scheduler(): bool {
    return function_exists( 'as_schedule_single_action' );
}

/**
 * Show admin notice if Composer dependencies are missing.
 */
function mighty_backup_dependency_notices(): void {
    $missing = [];
    if ( ! mighty_backup_has_sdk() ) {
        $missing[] = 'AWS SDK';
    }
    if ( ! mighty_backup_has_action_scheduler() ) {
        $missing[] = 'Action Scheduler';
    }
    if ( empty( $missing ) ) {
        return;
    }
    $message = sprintf(
        '<strong>Mighty Backup:</strong> Missing dependencies: %s. '
        . 'Please run <code>composer install</code> in the <code>mighty-backup</code> plugin directory, '
        . 'or download a pre-built release that includes the <code>vendor/</code> folder.',
        implode( ', ', $missing )
    );
    printf( '<div class="notice notice-error"><p>%s</p></div>', $message );
}
add_action( 'admin_notices', 'mighty_backup_dependency_notices' );
add_action( 'network_admin_notices', 'mighty_backup_dependency_notices' );

// Shared utilities.
require_once MIGHTY_BACKUP_DIR . 'includes/functions.php';

// Plugin classes.
require_once MIGHTY_BACKUP_DIR . 'includes/class-logger.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-log-stream.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-settings.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-api-endpoint.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-spaces-client.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-database-exporter.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-file-archiver.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-retention-manager.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-scheduler.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-backup-manager.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-dev-mode.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-devcontainer-manager.php';

// GitHub update checker.
if ( file_exists( MIGHTY_BACKUP_DIR . 'updates/plugin-update-checker.php' ) ) {
    require_once MIGHTY_BACKUP_DIR . 'updates/plugin-update-checker.php';
    \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/builtmighty/builtmighty-site-backup',
        MIGHTY_BACKUP_FILE,
        'mighty-backup'
    );
}

/**
 * Plugin activation.
 */
function mighty_backup_activate( $network_wide ) {
    $logger = new Mighty_Backup_Logger();
    $logger->create_table();

    // WP-Cron is blog-specific — on multisite, ensure the event is on the main site.
    if ( $network_wide && is_multisite() ) {
        switch_to_blog( get_main_site_id() );
    }

    $scheduler = new Mighty_Backup_Scheduler();
    $scheduler->schedule();

    // Unschedule old cron hook from pre-rename versions.
    wp_clear_scheduled_hook( 'bm_backup_scheduled' );

    if ( $network_wide && is_multisite() ) {
        restore_current_blog();
    }

    Mighty_Backup_Dev_Mode::maybe_set_live_url();
}
register_activation_hook( __FILE__, 'mighty_backup_activate' );

/**
 * Plugin deactivation.
 */
function mighty_backup_deactivate( $network_wide ) {
    // WP-Cron is blog-specific — on multisite, clear the event from the main site.
    if ( $network_wide && is_multisite() ) {
        switch_to_blog( get_main_site_id() );
    }

    $scheduler = new Mighty_Backup_Scheduler();
    $scheduler->unschedule();

    if ( $network_wide && is_multisite() ) {
        restore_current_blog();
    }
}
register_deactivation_hook( __FILE__, 'mighty_backup_deactivate' );

/**
 * Initialize the plugin.
 */
function mighty_backup_init() {
    // Dev mode — seed live URL for existing installs and hook admin notices.
    Mighty_Backup_Dev_Mode::maybe_set_live_url();
    $dev_mode = new Mighty_Backup_Dev_Mode();
    $dev_mode->init();

    // Settings page (always load — shows notice if deps missing).
    $settings = new Mighty_Backup_Settings();
    $settings->init();

    // Devcontainer manager — GitHub API version check and update.
    $devcontainer = new Mighty_Devcontainer_Manager( $settings );
    $devcontainer->init();

    // Codespace bootstrap endpoint.
    $endpoint = new Mighty_Backup_Api_Endpoint();
    $endpoint->init();

    // Backup manager — register Action Scheduler hooks.
    $manager = new Mighty_Backup_Manager();
    $manager->init();

    // Scheduler — hook into the WP-Cron event.
    $scheduler = new Mighty_Backup_Scheduler();
    $scheduler->init();

    // WP-CLI commands.
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once MIGHTY_BACKUP_DIR . 'includes/class-cli-command.php';
        WP_CLI::add_command( 'mighty-backup', 'Mighty_Backup_CLI_Command' );
    }
}
add_action( 'plugins_loaded', 'mighty_backup_init' );
