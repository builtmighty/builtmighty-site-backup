<?php
/**
 * PHPUnit bootstrap — loads the plugin classes and sets up WordPress function stubs.
 *
 * Uses Brain Monkey to stub WordPress functions so the plugin classes can be
 * instantiated and tested without a running WordPress environment.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress constants required by plugin files.
defined( 'ABSPATH' )           || define( 'ABSPATH', dirname( __DIR__ ) . '/' );
defined( 'BM_BACKUP_VERSION' ) || define( 'BM_BACKUP_VERSION', '1.9.0' );
defined( 'BM_BACKUP_DIR' )     || define( 'BM_BACKUP_DIR', dirname( __DIR__ ) . '/' );
defined( 'BM_BACKUP_URL' )     || define( 'BM_BACKUP_URL', 'http://localhost/' );
defined( 'DB_HOST' )           || define( 'DB_HOST', 'localhost' );
defined( 'DB_USER' )           || define( 'DB_USER', 'root' );
defined( 'DB_PASSWORD' )       || define( 'DB_PASSWORD', '' );
defined( 'DB_NAME' )           || define( 'DB_NAME', 'wordpress' );

// Load all plugin include files (skip vendor/updates).
foreach ( glob( dirname( __DIR__ ) . '/includes/class-*.php' ) as $file ) {
    require_once $file;
}
