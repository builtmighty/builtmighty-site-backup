<?php
/**
 * Plugin Name: BuiltMighty Site Backup
 * Plugin URI: https://github.com/builtmighty/builtmighty-site-backup
 * Description: Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.
 * Version: 1.1.0
 * Author: Built Mighty
 * Author URI: https://builtmighty.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: builtmighty-site-backup
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BM_BACKUP_VERSION', '1.1.0' );
define( 'BM_BACKUP_FILE', __FILE__ );
define( 'BM_BACKUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'BM_BACKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'BM_BACKUP_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoload (AWS SDK + Action Scheduler).
if ( file_exists( BM_BACKUP_DIR . 'vendor/autoload.php' ) ) {
    require_once BM_BACKUP_DIR . 'vendor/autoload.php';
}

// Load Action Scheduler if not already loaded by WooCommerce or another plugin.
if ( ! function_exists( 'as_schedule_single_action' ) ) {
    $as_path = BM_BACKUP_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
    if ( file_exists( $as_path ) ) {
        require_once $as_path;
    }
}

/**
 * Check if the AWS SDK is available.
 */
function bm_backup_has_sdk(): bool {
    return class_exists( 'Aws\S3\S3Client' );
}

/**
 * Check if Action Scheduler is available.
 */
function bm_backup_has_action_scheduler(): bool {
    return function_exists( 'as_schedule_single_action' );
}

/**
 * Show admin notice if Composer dependencies are missing.
 */
function bm_backup_dependency_notices(): void {
    $missing = [];
    if ( ! bm_backup_has_sdk() ) {
        $missing[] = 'AWS SDK';
    }
    if ( ! bm_backup_has_action_scheduler() ) {
        $missing[] = 'Action Scheduler';
    }
    if ( empty( $missing ) ) {
        return;
    }
    $message = sprintf(
        '<strong>BM Site Backup:</strong> Missing dependencies: %s. '
        . 'Please run <code>composer install</code> in the <code>builtmighty-site-backup</code> plugin directory, '
        . 'or download a pre-built release that includes the <code>vendor/</code> folder.',
        implode( ', ', $missing )
    );
    printf( '<div class="notice notice-error"><p>%s</p></div>', $message );
}
add_action( 'admin_notices', 'bm_backup_dependency_notices' );
add_action( 'network_admin_notices', 'bm_backup_dependency_notices' );

// Plugin classes.
require_once BM_BACKUP_DIR . 'includes/class-logger.php';
require_once BM_BACKUP_DIR . 'includes/class-settings.php';
require_once BM_BACKUP_DIR . 'includes/class-api-endpoint.php';
require_once BM_BACKUP_DIR . 'includes/class-spaces-client.php';
require_once BM_BACKUP_DIR . 'includes/class-database-exporter.php';
require_once BM_BACKUP_DIR . 'includes/class-file-archiver.php';
require_once BM_BACKUP_DIR . 'includes/class-retention-manager.php';
require_once BM_BACKUP_DIR . 'includes/class-scheduler.php';
require_once BM_BACKUP_DIR . 'includes/class-backup-manager.php';

/**
 * Plugin activation.
 */
function bm_backup_activate( $network_wide ) {
    $logger = new BM_Backup_Logger();
    $logger->create_table();

    $scheduler = new BM_Backup_Scheduler();
    $scheduler->schedule();
}
register_activation_hook( __FILE__, 'bm_backup_activate' );

/**
 * Plugin deactivation.
 */
function bm_backup_deactivate() {
    $scheduler = new BM_Backup_Scheduler();
    $scheduler->unschedule();
}
register_deactivation_hook( __FILE__, 'bm_backup_deactivate' );

/**
 * Initialize the plugin.
 */
function bm_backup_init() {
    // Settings page (always load — shows notice if deps missing).
    $settings = new BM_Backup_Settings();
    $settings->init();

    // Codespace bootstrap endpoint.
    $endpoint = new BM_Backup_Api_Endpoint();
    $endpoint->init();

    // Backup manager — register Action Scheduler hooks.
    $manager = new BM_Backup_Manager();
    $manager->init();

    // Scheduler — hook into the WP-Cron event.
    $scheduler = new BM_Backup_Scheduler();
    $scheduler->init();

    // WP-CLI commands.
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once BM_BACKUP_DIR . 'includes/class-cli-command.php';
        WP_CLI::add_command( 'bm-backup', 'BM_Backup_CLI_Command' );
    }
}
add_action( 'plugins_loaded', 'bm_backup_init' );
