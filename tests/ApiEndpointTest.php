<?php
/**
 * Tests for the /codespace-config payload contract.
 *
 * The Codespace bootstrap consumes this object field-for-field, so the shape,
 * the types (everything a string except `multisource`) and the documented
 * defaults are all part of the contract.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class ApiEndpointTest extends TestCase {

    /** Every key the bootstrap expects, in payload order. */
    private const EXPECTED_KEYS = [
        'do_spaces_key',
        'do_spaces_secret',
        'do_spaces_endpoint',
        'do_spaces_bucket',
        'client_path',
        'repository',
        'hosting_provider',
        'remote_domain',
        'php_version',
        'db_engine',
        'db_version',
        'multisource',
        'timezone',
        'platform',
        'source_name',
    ];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'wp_parse_args' )->alias( static function ( $args, $defaults ) {
            return array_merge( $defaults, (array) $args );
        } );
        Functions\when( 'wp_parse_url' )->alias( static function ( $url, $component = -1 ) {
            return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
        } );
        Functions\when( 'get_site_url' )->justReturn( 'https://acmestore.com' );
        Functions\when( 'sanitize_title' )->alias( static function ( $value ) {
            return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' );
        } );
        Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) {
            return $name === 'timezone_string' ? 'America/Denver' : $default;
        } );

        $this->set_db_server_info( '8.0.35' );
        $this->reset_settings_cache();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        $this->reset_settings_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Mighty_Backup_Settings caches get_all() in a static shared across
     * instances, so it has to be cleared between cases or the first stub wins.
     */
    private function reset_settings_cache(): void {
        $property = new ReflectionProperty( Mighty_Backup_Settings::class, 'cached_settings' );
        $property->setAccessible( true );
        $property->setValue( null, null );
    }

    private function set_db_server_info( string $info ): void {
        $GLOBALS['wpdb'] = new class( $info ) {
            private string $info;
            public function __construct( string $info ) {
                $this->info = $info;
            }
            public function db_server_info(): string {
                return $this->info;
            }
            public function db_version(): string {
                return $this->info;
            }
        };
    }

    /**
     * @param array $overrides Values merged over a minimally-configured site.
     */
    private function payload( array $overrides = [] ): array {
        Functions\when( 'get_site_option' )->justReturn( array_merge( [
            'spaces_access_key'     => 'DO00ABCDEFGHIJKLMNOP',
            'spaces_secret_key_enc' => '', // Empty => get_secret_key() short-circuits.
            'spaces_endpoint'       => 'nyc3.digitaloceanspaces.com',
            'spaces_bucket'         => 'builtmighty-backups',
            'client_path'           => 'acme-store',
        ], $overrides ) );

        $this->reset_settings_cache();

        return Mighty_Backup_Api_Endpoint::build_config_payload();
    }

    public function test_payload_has_exactly_the_documented_keys(): void {
        $payload = $this->payload();

        $this->assertSame( self::EXPECTED_KEYS, array_keys( $payload ) );
    }

    public function test_every_value_is_a_string_except_multisource(): void {
        $payload = $this->payload();

        $this->assertIsBool( $payload['multisource'], 'multisource must be a real JSON boolean' );

        foreach ( $payload as $key => $value ) {
            if ( $key === 'multisource' ) {
                continue;
            }
            $this->assertIsString( $value, "{$key} must be a string" );
        }
    }

    public function test_credentials_and_paths_are_passed_through(): void {
        $payload = $this->payload();

        $this->assertSame( 'DO00ABCDEFGHIJKLMNOP', $payload['do_spaces_key'] );
        $this->assertSame( 'nyc3.digitaloceanspaces.com', $payload['do_spaces_endpoint'] );
        $this->assertSame( 'builtmighty-backups', $payload['do_spaces_bucket'] );
        $this->assertSame( 'acme-store', $payload['client_path'] );
        $this->assertSame( 'acmestore.com', $payload['remote_domain'] );
        $this->assertSame( 'wordpress', $payload['platform'] );
    }

    public function test_client_path_and_repository_agree(): void {
        $payload = $this->payload();

        // `repository` is the pre-rename name; bootstraps in the wild still read
        // it, so both keys must carry the same value.
        $this->assertSame( $payload['client_path'], $payload['repository'] );
        $this->assertSame( 'acme-store', $payload['repository'] );
    }

    public function test_endpoint_is_reported_host_only(): void {
        $payload = $this->payload( [ 'spaces_endpoint' => 'https://nyc3.digitaloceanspaces.com/' ] );

        // The bootstrap expands this into host_bucket = %(bucket)s.<endpoint>,
        // which breaks on anything but a bare host.
        $this->assertSame( 'nyc3.digitaloceanspaces.com', $payload['do_spaces_endpoint'] );
    }

    public function test_hosting_provider_defaults_to_generic(): void {
        $this->assertSame( 'generic', $this->payload( [ 'hosting_provider' => '' ] )['hosting_provider'] );
    }

    public function test_hosting_provider_is_lowercased(): void {
        $this->assertSame( 'pressable', $this->payload( [ 'hosting_provider' => 'Pressable' ] )['hosting_provider'] );
    }

    public function test_environment_fields_are_detected_when_not_overridden(): void {
        $payload = $this->payload();

        $this->assertSame( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $payload['php_version'] );
        $this->assertSame( 'mysql', $payload['db_engine'] );
        $this->assertSame( '8.0.35', $payload['db_version'] );
        $this->assertSame( 'America/Denver', $payload['timezone'] );
    }

    public function test_mariadb_is_detected_and_version_unmangled(): void {
        $this->set_db_server_info( '5.5.5-10.6.16-MariaDB-1:10.6.16+maria~ubu2004' );
        $payload = $this->payload();

        $this->assertSame( 'mariadb', $payload['db_engine'] );
        $this->assertSame( '10.6.16', $payload['db_version'] );
    }

    public function test_overrides_win_over_detection(): void {
        $payload = $this->payload( [
            'php_version' => '8.4',
            'db_engine'   => 'mariadb',
            'db_version'  => '11.4.2',
            'timezone'    => 'Europe/Berlin',
        ] );

        $this->assertSame( '8.4', $payload['php_version'] );
        $this->assertSame( 'mariadb', $payload['db_engine'] );
        $this->assertSame( '11.4.2', $payload['db_version'] );
        $this->assertSame( 'Europe/Berlin', $payload['timezone'] );
    }

    public function test_malformed_stored_overrides_fall_back_to_detection(): void {
        // The form and the CLI both reject these, but the option can be written
        // by other means — a garbage field would break the bootstrap silently.
        $payload = $this->payload( [
            'php_version' => '8.2.10-1+ubuntu',
            'db_engine'   => 'postgres',
            'timezone'    => '+05:30',
        ] );

        $this->assertSame( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $payload['php_version'] );
        $this->assertSame( 'mysql', $payload['db_engine'] );
        $this->assertSame( 'America/Denver', $payload['timezone'] );
    }

    public function test_multisource_off_reports_the_default_stem(): void {
        $payload = $this->payload();

        $this->assertFalse( $payload['multisource'] );
        $this->assertSame( 'backup', $payload['source_name'] );
    }

    public function test_multisource_on_reports_the_configured_site_name(): void {
        $payload = $this->payload( [
            'multisource'      => true,
            'multisource_name' => 'acme-store',
        ] );

        $this->assertTrue( $payload['multisource'] );
        $this->assertSame( 'acme-store', $payload['source_name'] );
    }

    public function test_multisource_on_derives_site_name_from_domain_when_blank(): void {
        $payload = $this->payload( [
            'multisource'      => true,
            'multisource_name' => '',
        ] );

        $this->assertTrue( $payload['multisource'] );
        $this->assertSame( 'acmestore-com', $payload['source_name'] );
    }

    public function test_unconfigured_site_still_returns_a_complete_payload(): void {
        Functions\when( 'get_site_option' )->justReturn( [] );
        $this->reset_settings_cache();

        $payload = Mighty_Backup_Api_Endpoint::build_config_payload();

        $this->assertSame( self::EXPECTED_KEYS, array_keys( $payload ) );
        $this->assertSame( '', $payload['do_spaces_key'] );
        $this->assertSame( '', $payload['client_path'] );
        // Defaults still apply — the bootstrap never sees a missing key.
        $this->assertSame( 'generic', $payload['hosting_provider'] );
        $this->assertSame( 'wordpress', $payload['platform'] );
        $this->assertFalse( $payload['multisource'] );
    }
}
