<?php
/**
 * Tests for Mighty_Backup_Settings — encryption and configuration validation.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub WordPress functions used in Settings.
        Functions\when( 'wp_salt' )->justReturn( str_repeat( 'a', 64 ) );
        Functions\when( 'get_site_option' )->justReturn( [] );
        Functions\when( 'update_site_option' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, (array) $args );
        } );

        // get_all() memoizes into a static shared across instances, so without
        // this the first test's stubbed options leak into every later case.
        $this->reset_settings_cache();
    }

    protected function tearDown(): void {
        $this->reset_settings_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_configured_returns_false_when_empty(): void {
        $settings = new Mighty_Backup_Settings();
        $this->assertFalse( $settings->is_configured() );
    }

    public function test_is_configured_returns_true_when_all_fields_set(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'spaces_access_key'     => 'AKIAIOSFODNN7EXAMPLE',
            'spaces_secret_key_enc' => 'encrypted_value',
            'spaces_endpoint'       => 'nyc3.digitaloceanspaces.com',
            'spaces_bucket'         => 'my-bucket',
            'client_path'           => 'clientname',
        ] );

        $settings = new Mighty_Backup_Settings();
        $this->assertTrue( $settings->is_configured() );
    }

    public function test_is_configured_returns_false_when_missing_bucket(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'spaces_access_key'     => 'AKIAIOSFODNN7EXAMPLE',
            'spaces_secret_key_enc' => 'encrypted_value',
            'spaces_endpoint'       => 'nyc3.digitaloceanspaces.com',
            'spaces_bucket'         => '',
            'client_path'           => 'clientname',
        ] );

        $settings = new Mighty_Backup_Settings();
        $this->assertFalse( $settings->is_configured() );
    }

    public function test_encrypt_decrypt_round_trip(): void {
        $settings = new Mighty_Backup_Settings();

        $reflection = new ReflectionClass( $settings );
        $encrypt    = $reflection->getMethod( 'encrypt' );
        $decrypt    = $reflection->getMethod( 'decrypt' );
        $encrypt->setAccessible( true );
        $decrypt->setAccessible( true );

        $plaintext = 'super-secret-do-spaces-key-12345';
        $encrypted = $encrypt->invoke( $settings, $plaintext );

        $this->assertNotEquals( $plaintext, $encrypted, 'Encrypted value should differ from plaintext.' );
        $this->assertEquals( $plaintext, $decrypt->invoke( $settings, $encrypted ), 'Decrypted value should match original.' );
    }

    public function test_encrypt_produces_different_ciphertexts(): void {
        $settings = new Mighty_Backup_Settings();

        $reflection = new ReflectionClass( $settings );
        $encrypt    = $reflection->getMethod( 'encrypt' );
        $encrypt->setAccessible( true );

        $plaintext   = 'same-input-value';
        $encrypted_1 = $encrypt->invoke( $settings, $plaintext );
        $encrypted_2 = $encrypt->invoke( $settings, $plaintext );

        // AES-256-CBC uses a random IV so each call produces a different ciphertext.
        $this->assertNotEquals( $encrypted_1, $encrypted_2, 'Each encryption of the same plaintext should use a unique IV.' );
    }

    public function test_decrypt_returns_empty_for_invalid_data(): void {
        $settings = new Mighty_Backup_Settings();

        $reflection = new ReflectionClass( $settings );
        $decrypt    = $reflection->getMethod( 'decrypt' );
        $decrypt->setAccessible( true );

        $this->assertSame( '', $decrypt->invoke( $settings, base64_encode( 'short' ) ) );
        $this->assertSame( '', $decrypt->invoke( $settings, '' ) );
    }

    public function test_get_secret_key_returns_empty_when_not_set(): void {
        $settings = new Mighty_Backup_Settings();
        $this->assertSame( '', $settings->get_secret_key() );
    }

    public function test_get_returns_default_when_key_missing(): void {
        $settings = new Mighty_Backup_Settings();
        $this->assertSame( 'daily', $settings->get( 'schedule_frequency' ) );
        $this->assertSame( '03:00', $settings->get( 'schedule_time' ) );
        $this->assertSame( 7, $settings->get( 'retention_count' ) );
    }

    /* ---------------------------------------------------------------------
     * Codespace environment settings + object naming
     * ------------------------------------------------------------------ */

    /**
     * get_all() caches into a static shared across instances, so it has to be
     * cleared whenever a test restubs get_site_option.
     */
    private function reset_settings_cache(): void {
        $property = new ReflectionProperty( Mighty_Backup_Settings::class, 'cached_settings' );
        $property->setAccessible( true );
        $property->setValue( null, null );
    }

    /**
     * Stub the WordPress sanitizers that sanitize_settings() leans on.
     */
    private function stub_sanitizers(): void {
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'sanitize_email' )->alias( static fn( $v ) => (string) $v );
        Functions\when( 'sanitize_title' )->alias( static function ( $v ) {
            return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $v ) ), '-' );
        } );
        Functions\when( 'wp_parse_url' )->alias( static function ( $url, $component = -1 ) {
            return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
        } );
        Functions\when( 'get_site_url' )->justReturn( 'https://acmestore.com' );
    }

    private function sanitize( array $input ): array {
        $this->stub_sanitizers();
        $this->reset_settings_cache();

        return ( new Mighty_Backup_Settings() )->sanitize_settings( $input );
    }

    public function test_sanitize_preserves_the_codespace_environment_keys(): void {
        // sanitize_settings() rebuilds the option from scratch, so a key it
        // forgets to write is silently wiped on every save.
        $sanitized = $this->sanitize( [
            'php_version'      => '8.3',
            'db_engine'        => 'mariadb',
            'db_version'       => '10.6.16',
            'timezone'         => 'America/Denver',
            'multisource'      => '1',
            'multisource_name' => 'Acme Store',
        ] );

        $this->assertSame( '8.3', $sanitized['php_version'] );
        $this->assertSame( 'mariadb', $sanitized['db_engine'] );
        $this->assertSame( '10.6.16', $sanitized['db_version'] );
        $this->assertSame( 'America/Denver', $sanitized['timezone'] );
        $this->assertTrue( $sanitized['multisource'] );
        $this->assertSame( 'acme-store', $sanitized['multisource_name'] );
    }

    public function test_sanitize_discards_invalid_environment_values(): void {
        $sanitized = $this->sanitize( [
            'php_version' => '8.2.10',              // major.minor only
            'db_engine'   => 'postgres',            // not a supported engine
            'timezone'    => 'Mars/Olympus_Mons',   // not an IANA zone
        ] );

        // Blank means "detect", which is the safe fallback for a bad value.
        $this->assertSame( '', $sanitized['php_version'] );
        $this->assertSame( '', $sanitized['db_engine'] );
        $this->assertSame( '', $sanitized['timezone'] );
    }

    public function test_sanitize_lowercases_db_engine(): void {
        $this->assertSame( 'mariadb', $this->sanitize( [ 'db_engine' => 'MariaDB' ] )['db_engine'] );
    }

    public function test_sanitize_defaults_multisource_to_false_when_absent(): void {
        $sanitized = $this->sanitize( [] );

        $this->assertFalse( $sanitized['multisource'] );
        $this->assertSame( '', $sanitized['multisource_name'] );
    }

    public function test_sanitize_normalizes_the_spaces_endpoint_to_a_host(): void {
        // Spaces_Client builds 'https://' . $endpoint, so a stored scheme yields
        // "https://https://…" and every S3 call fails.
        $sanitized = $this->sanitize( [ 'spaces_endpoint' => 'https://nyc3.digitaloceanspaces.com/' ] );

        $this->assertSame( 'nyc3.digitaloceanspaces.com', $sanitized['spaces_endpoint'] );
    }

    public function test_object_stem_is_backup_when_multisource_is_off(): void {
        $this->stub_sanitizers();
        Functions\when( 'get_site_option' )->justReturn( [ 'multisource' => false ] );
        $this->reset_settings_cache();

        // Every object written before multisource existed is named "backup-*".
        $this->assertSame( 'backup', ( new Mighty_Backup_Settings() )->get_object_stem() );
    }

    public function test_object_stem_uses_the_configured_name_when_multisource_is_on(): void {
        $this->stub_sanitizers();
        Functions\when( 'get_site_option' )->justReturn( [
            'multisource'      => true,
            'multisource_name' => 'acme-store',
        ] );
        $this->reset_settings_cache();

        $this->assertSame( 'acme-store', ( new Mighty_Backup_Settings() )->get_object_stem() );
    }

    public function test_object_stem_falls_back_to_a_domain_slug(): void {
        $this->stub_sanitizers();
        Functions\when( 'get_site_option' )->justReturn( [
            'multisource'      => true,
            'multisource_name' => '',
        ] );
        $this->reset_settings_cache();

        $this->assertSame( 'acmestore-com', ( new Mighty_Backup_Settings() )->get_object_stem() );
    }

    public function test_cli_rejects_a_malformed_php_version(): void {
        $this->stub_sanitizers();
        $this->reset_settings_cache();

        $this->expectException( InvalidArgumentException::class );
        // The bootstrap reduces this field to major.minor, so a patch version is
        // a configuration mistake — the CLI must not store it silently.
        ( new Mighty_Backup_Settings() )->set_value( 'php_version', '8.2.10' );
    }

    public function test_cli_rejects_a_non_iana_timezone(): void {
        $this->stub_sanitizers();
        $this->reset_settings_cache();

        $this->expectException( InvalidArgumentException::class );
        ( new Mighty_Backup_Settings() )->set_value( 'timezone', '+05:30' );
    }

    public function test_cli_accepts_valid_environment_values(): void {
        $this->stub_sanitizers();

        // set_value() writes through save_all() and then re-reads, so the option
        // stub has to actually hold state for a round-trip to mean anything.
        $store = [];
        Functions\when( 'get_site_option' )->alias( static function ( $name, $default = false ) use ( &$store ) {
            return $store[ $name ] ?? $default;
        } );
        Functions\when( 'update_site_option' )->alias( static function ( $name, $value ) use ( &$store ) {
            $store[ $name ] = $value;
            return true;
        } );
        $this->reset_settings_cache();

        $settings = new Mighty_Backup_Settings();
        $settings->set_value( 'php_version', '8.4' );
        $settings->set_value( 'timezone', 'America/Denver' );
        $settings->set_value( 'multisource', 'yes' );
        $settings->set_value( 'multisource_name', 'Acme Store' );

        $this->assertSame( '8.4', $settings->get_value( 'php_version' ) );
        $this->assertSame( 'America/Denver', $settings->get_value( 'timezone' ) );
        $this->assertSame( '1', $settings->get_value( 'multisource' ) );
        $this->assertSame( 'acme-store', $settings->get_value( 'multisource_name' ) );
    }

    public function test_environment_keys_are_cli_writable(): void {
        $writable = ( new Mighty_Backup_Settings() )->get_writable_keys();

        foreach ( [ 'php_version', 'db_engine', 'db_version', 'timezone', 'multisource', 'multisource_name' ] as $key ) {
            $this->assertContains( $key, $writable, "{$key} should be settable via WP-CLI" );
        }
    }
}
