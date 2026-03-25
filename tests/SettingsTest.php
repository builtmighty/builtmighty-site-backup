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
    }

    protected function tearDown(): void {
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
}
