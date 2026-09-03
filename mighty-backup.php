<?php
/**
 * Plugin Name: Mighty Backup
 * Plugin URI: https://github.com/builtmighty/mighty-backup
 * Description: Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.
 * Version: 3.0.1
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

define( 'MIGHTY_BACKUP_VERSION', '3.0.1' );
define( 'MIGHTY_BACKUP_FILE', __FILE__ );
define( 'MIGHTY_BACKUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MIGHTY_BACKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'MIGHTY_BACKUP_BASENAME', plugin_basename( __FILE__ ) );

// Composer autoload (AWS SDK + Action Scheduler).
//
// The PHP version is checked BEFORE the require on purpose. vendor/composer/
// platform_check.php throws an uncaught RuntimeException on PHP below 8.1, which
// would white-screen the entire site — front end included — before WordPress can
// reach admin_notices. WordPress's "Requires PHP" header blocks activation and
// updates, but not a plugin that is already active when a host downgrades PHP.
// Skipping the autoloader instead degrades to the dependency notice below, which
// explains that the PHP version is the problem.
if ( PHP_VERSION_ID >= 80100 && file_exists( MIGHTY_BACKUP_DIR . 'vendor/autoload.php' ) ) {
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
 * PHP extensions this plugin genuinely cannot run without.
 *
 * simplexml/json/pcre are aws-sdk-php's own `require` entries; openssl is ours,
 * for the AES-256-CBC encryption of the stored Spaces secret key. mbstring is
 * deliberately absent — symfony/polyfill-mbstring ships in vendor/ and covers it,
 * so reporting it would be a false alarm.
 */
const MIGHTY_BACKUP_REQUIRED_EXTENSIONS = [ 'simplexml', 'json', 'pcre', 'openssl' ];

/**
 * Collect the facts that explain *why* dependencies are unavailable.
 *
 * The plugin ships its own vendor/ tree, so "missing dependencies" is never a
 * matter of the user not having run Composer — it means something removed the
 * tree, or the platform can't load it. Report which.
 *
 * @return array{autoloader:bool,php_ok:bool,php_version:string,missing_extensions:string[]}
 */
function mighty_backup_dependency_diagnostics(): array {
    $missing_extensions = [];
    foreach ( MIGHTY_BACKUP_REQUIRED_EXTENSIONS as $extension ) {
        if ( ! extension_loaded( $extension ) ) {
            $missing_extensions[] = $extension;
        }
    }

    return [
        'autoloader'         => file_exists( MIGHTY_BACKUP_DIR . 'vendor/autoload.php' ),
        'php_ok'             => PHP_VERSION_ID >= 80100,
        'php_version'        => PHP_VERSION,
        'missing_extensions' => $missing_extensions,
    ];
}

/**
 * Show an admin notice if the bundled dependencies aren't loadable.
 *
 * Deliberately does NOT tell the operator to run `composer install`: the tree is
 * bundled, and the sites where this fires are typically ones where we only have
 * WP admin access and no shell at all.
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

    $diagnostics = mighty_backup_dependency_diagnostics();

    // Lead with the actual cause rather than a generic instruction.
    if ( ! $diagnostics['php_ok'] ) {
        $cause = sprintf(
            'This site runs PHP %s, but Mighty Backup requires PHP 8.1 or newer. '
            . 'Upgrading PHP is the fix — reinstalling will not help.',
            esc_html( $diagnostics['php_version'] )
        );
    } elseif ( ! $diagnostics['autoloader'] ) {
        $cause = 'The bundled <code>vendor/</code> folder is missing from this install. '
            . 'Mighty Backup ships its dependencies, so this usually means the folder was '
            . 'stripped during deployment, or the plugin was restored from a backup taken '
            . 'before version 3.0.1.';
    } elseif ( ! empty( $diagnostics['missing_extensions'] ) ) {
        $cause = sprintf(
            'The bundled dependencies are present, but PHP is missing the %s extension(s) they need. '
            . 'Ask the host to enable them.',
            '<code>' . implode( '</code>, <code>', array_map( 'esc_html', $diagnostics['missing_extensions'] ) ) . '</code>'
        );
    } else {
        $cause = 'The bundled <code>vendor/</code> folder is present but could not be loaded, '
            . 'which usually means it is incomplete.';
    }

    // Recovery that works with WP admin alone — no shell required.
    $recovery = 'To repair: download the latest release ZIP and reinstall it from '
        . '<strong>Plugins &rarr; Add New &rarr; Upload Plugin</strong>, choosing '
        . '&ldquo;Replace current with uploaded&rdquo;.';
    if ( $diagnostics['php_ok'] ) {
        $recovery .= ' <a href="https://github.com/builtmighty/mighty-backup/releases/latest/download/mighty-backup.zip">Download the latest release</a>.';
    }

    $repair_button = '';
    if (
        $diagnostics['php_ok']
        && function_exists( 'mighty_backup_is_authorized_user' )
        && mighty_backup_is_authorized_user()
        && class_exists( 'Mighty_Backup_Dependency_Repair' )
    ) {
        $repair_button = sprintf(
            '</p><p><a href="%s" class="button button-primary">Repair dependencies automatically</a>',
            esc_url( Mighty_Backup_Dependency_Repair::repair_url() )
        );
    }

    printf(
        '<div class="notice notice-error"><p><strong>Mighty Backup:</strong> Missing dependencies: %s.</p>'
        . '<p>%s</p><p>%s%s</p></div>',
        esc_html( implode( ', ', $missing ) ),
        $cause,
        $recovery,
        $repair_button
    );
}
add_action( 'admin_notices', 'mighty_backup_dependency_notices' );
add_action( 'network_admin_notices', 'mighty_backup_dependency_notices' );

// Shared utilities.
require_once MIGHTY_BACKUP_DIR . 'includes/functions.php';

// Plugin classes.
require_once MIGHTY_BACKUP_DIR . 'includes/class-error-translator.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-dependency-repair.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-environment.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-logger.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-log-stream.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-settings.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-api-endpoint.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-spaces-client.php';
require_once MIGHTY_BACKUP_DIR . 'includes/class-placeholder-repair.php';
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
    $mighty_backup_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/builtmighty/mighty-backup',
        MIGHTY_BACKUP_FILE,
        'mighty-backup'
    );
    $mighty_backup_updater->setBranch( 'main' );
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

    // Install (or refresh) the bucket lifecycle rule that aborts incomplete
    // multipart uploads after 1 day. Belt-and-suspenders backstop for the
    // explicit abort_upload_state call inside Spaces_Client::upload — if a
    // worker SIGKILL skips that abort, Spaces will still reclaim the orphans.
    // Best-effort: only runs when settings are populated (creds known).
    try {
        $settings = new Mighty_Backup_Settings();
        if ( $settings->is_configured() && mighty_backup_has_sdk() ) {
            ( new Mighty_Backup_Spaces_Client( $settings ) )->ensure_lifecycle_policy();
        }
    } catch ( \Throwable $e ) {
        // Activation must not fail. Operator can re-run via the settings
        // page or `wp mighty-backup` once creds are in place.
        error_log( 'Mighty Backup activation: ensure_lifecycle_policy skipped — ' . $e->getMessage() );
    }
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
    // Dependency repair — must init even (especially) when the SDK is missing,
    // since that is exactly when its admin-post handler is needed.
    ( new Mighty_Backup_Dependency_Repair() )->init();

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
        require_once MIGHTY_BACKUP_DIR . 'includes/class-repair-cli-command.php';
        WP_CLI::add_command( 'mighty-backup', 'Mighty_Backup_CLI_Command' );
        WP_CLI::add_command( 'mighty-backup settings', 'Mighty_Backup_Settings_CLI_Command' );
        WP_CLI::add_command( 'mighty-backup api-key', 'Mighty_Backup_Api_Key_CLI_Command' );
        WP_CLI::add_command( 'mighty-backup devcontainer', 'Mighty_Backup_Devcontainer_CLI_Command' );
        WP_CLI::add_command( 'mighty-backup repair', 'Mighty_Backup_Repair_CLI_Command' );
    }
}
add_action( 'plugins_loaded', 'mighty_backup_init' );
