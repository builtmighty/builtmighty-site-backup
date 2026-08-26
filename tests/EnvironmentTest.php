<?php
/**
 * Tests for Mighty_Backup_Environment — the runtime detection that feeds the
 * Codespace bootstrap payload.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'wp_parse_url' )->alias(
            static function ( $url, $component = -1 ) {
                return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
            }
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_php_version_is_major_minor_only(): void {
        $this->assertSame(
            PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            Mighty_Backup_Environment::php_version()
        );
        $this->assertMatchesRegularExpression( '/^\d+\.\d+$/', Mighty_Backup_Environment::php_version() );
    }

    /**
     * @dataProvider db_server_info_provider
     */
    public function test_parse_db_server_info( string $raw, string $engine, string $version ): void {
        $parsed = Mighty_Backup_Environment::parse_db_server_info( $raw );

        $this->assertSame( $engine, $parsed['engine'], "engine for: {$raw}" );
        $this->assertSame( $version, $parsed['version'], "version for: {$raw}" );
    }

    public static function db_server_info_provider(): array {
        return [
            'plain mysql 8'        => [ '8.0.35', 'mysql', '8.0.35' ],
            'mysql 5.7 with -log'  => [ '5.7.44-log', 'mysql', '5.7.44' ],
            'mysql with suffix'    => [ '8.0.36-0ubuntu0.22.04.1', 'mysql', '8.0.36' ],
            'percona'              => [ '8.0.35-27', 'mysql', '8.0.35' ],
            'mariadb 10'           => [ '10.6.16-MariaDB-1:10.6.16+maria~ubu2004', 'mariadb', '10.6.16' ],
            // MariaDB 10+ prefixes a fake 5.5.5 for ancient MySQL clients. Without
            // stripping it, every MariaDB 10/11 would report as 5.5.5.
            'mariadb compat pfx'   => [ '5.5.5-10.6.16-MariaDB-1:10.6.16+maria~ubu2004', 'mariadb', '10.6.16' ],
            'mariadb 11'           => [ '5.5.5-11.4.2-MariaDB', 'mariadb', '11.4.2' ],
            'mariadb lowercase'    => [ '10.11.6-mariadb', 'mariadb', '10.11.6' ],
            'two-part version'     => [ '8.0-preview', 'mysql', '8.0' ],
            'empty string'         => [ '', 'mysql', '' ],
            'unparseable'          => [ 'unknown', 'mysql', '' ],
        ];
    }

    public function test_timezone_prefers_valid_timezone_string(): void {
        Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) {
            return $name === 'timezone_string' ? 'America/Denver' : $default;
        } );

        $this->assertSame( 'America/Denver', Mighty_Backup_Environment::timezone() );
    }

    public function test_timezone_ignores_invalid_timezone_string(): void {
        Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) {
            if ( $name === 'timezone_string' ) {
                return 'Mars/Olympus_Mons';
            }
            return $name === 'gmt_offset' ? 0 : $default;
        } );

        $this->assertSame( 'UTC', Mighty_Backup_Environment::timezone() );
    }

    public function test_timezone_falls_back_to_zone_for_utc_offset(): void {
        // Settings → General "UTC-7" leaves timezone_string empty; the payload
        // still has to carry an IANA name the bootstrap can use.
        Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) {
            if ( $name === 'timezone_string' ) {
                return '';
            }
            return $name === 'gmt_offset' ? -7 : $default;
        } );

        $timezone = Mighty_Backup_Environment::timezone();

        $this->assertNotSame( '', $timezone );
        $this->assertTrue(
            Mighty_Backup_Environment::is_valid_timezone( $timezone ),
            "Expected an IANA zone name, got: {$timezone}"
        );
    }

    public function test_timezone_defaults_to_utc_with_no_settings(): void {
        Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) {
            return $name === 'timezone_string' ? '' : 0;
        } );

        $this->assertSame( 'UTC', Mighty_Backup_Environment::timezone() );
    }

    public function test_is_valid_timezone(): void {
        $this->assertTrue( Mighty_Backup_Environment::is_valid_timezone( 'America/Denver' ) );
        $this->assertTrue( Mighty_Backup_Environment::is_valid_timezone( 'UTC' ) );
        $this->assertFalse( Mighty_Backup_Environment::is_valid_timezone( '+05:30' ) );
        $this->assertFalse( Mighty_Backup_Environment::is_valid_timezone( '' ) );
    }

    /**
     * @dataProvider endpoint_provider
     */
    public function test_normalize_endpoint( string $input, string $expected ): void {
        $this->assertSame( $expected, Mighty_Backup_Environment::normalize_endpoint( $input ) );
    }

    public static function endpoint_provider(): array {
        return [
            'already bare'       => [ 'nyc3.digitaloceanspaces.com', 'nyc3.digitaloceanspaces.com' ],
            'https scheme'       => [ 'https://nyc3.digitaloceanspaces.com', 'nyc3.digitaloceanspaces.com' ],
            'http scheme'        => [ 'http://sfo3.digitaloceanspaces.com', 'sfo3.digitaloceanspaces.com' ],
            'trailing slash'     => [ 'https://nyc3.digitaloceanspaces.com/', 'nyc3.digitaloceanspaces.com' ],
            'with path'          => [ 'https://nyc3.digitaloceanspaces.com/my-bucket', 'nyc3.digitaloceanspaces.com' ],
            'port stripped'      => [ 'https://nyc3.digitaloceanspaces.com:443', 'nyc3.digitaloceanspaces.com' ],
            'query stripped'     => [ 'nyc3.digitaloceanspaces.com/?x=1', 'nyc3.digitaloceanspaces.com' ],
            'surrounding space'  => [ '  nyc3.digitaloceanspaces.com  ', 'nyc3.digitaloceanspaces.com' ],
            'empty'              => [ '', '' ],
        ];
    }
}
