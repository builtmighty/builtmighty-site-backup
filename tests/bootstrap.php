<?php
/**
 * PHPUnit bootstrap — loads the plugin classes and sets up WordPress function stubs.
 *
 * Uses Brain Monkey to stub WordPress functions so the plugin classes can be
 * instantiated and tested without a running WordPress environment.
 */

/*
 * Dev dependencies (PHPUnit, Brain Monkey, Mockery) install into vendor-dev/ so
 * they can never leak into the committed vendor/ tree, where Composer would
 * eagerly include them on every WordPress request. Prefer vendor-dev/ so a
 * developer with both trees always gets the one that actually has PHPUnit.
 */
$mb_autoload = null;
foreach ( [ '/vendor-dev/autoload.php', '/vendor/autoload.php' ] as $mb_candidate ) {
    if ( file_exists( dirname( __DIR__ ) . $mb_candidate ) ) {
        $mb_autoload = dirname( __DIR__ ) . $mb_candidate;
        break;
    }
}

if ( null === $mb_autoload ) {
    fwrite(
        STDERR,
        "No Composer autoloader found.\n"
        . "Install the dev dependencies first:\n"
        . "  bash:       COMPOSER_VENDOR_DIR=vendor-dev composer install\n"
        . "  PowerShell: \$env:COMPOSER_VENDOR_DIR=\"vendor-dev\"; composer install\n"
    );
    exit( 1 );
}

require_once $mb_autoload;

// WordPress constants required by plugin files.
defined( 'ABSPATH' )           || define( 'ABSPATH', dirname( __DIR__ ) . '/' );
defined( 'MIGHTY_BACKUP_VERSION' ) || define( 'MIGHTY_BACKUP_VERSION', '1.12.0' );
defined( 'MIGHTY_BACKUP_DIR' )     || define( 'MIGHTY_BACKUP_DIR', dirname( __DIR__ ) . '/' );
defined( 'MIGHTY_BACKUP_URL' )     || define( 'MIGHTY_BACKUP_URL', 'http://localhost/' );
defined( 'DB_HOST' )           || define( 'DB_HOST', 'localhost' );
defined( 'DB_USER' )           || define( 'DB_USER', 'root' );
defined( 'DB_PASSWORD' )       || define( 'DB_PASSWORD', '' );
defined( 'DB_NAME' )           || define( 'DB_NAME', 'wordpress' );

// Time constants WordPress defines in wp-includes/default-constants.php.
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' )   || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );

// wpdb output-format constants used by tests that exercise paginated queries.
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );
defined( 'OBJECT' )  || define( 'OBJECT', 'OBJECT' );

// Load all plugin include files (skip vendor/updates).
foreach ( glob( dirname( __DIR__ ) . '/includes/class-*.php' ) as $file ) {
    require_once $file;
}
