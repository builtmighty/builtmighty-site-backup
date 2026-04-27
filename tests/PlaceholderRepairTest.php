<?php
/**
 * Tests for Mighty_Backup_Placeholder_Repair — string-level sanitization.
 *
 * The DB-touching methods (detect_sample_token, count_corruption, repair_all)
 * require a live $wpdb and aren't covered here. They're verified end-to-end
 * via the manual test plan in the implementation plan.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class PlaceholderRepairTest extends TestCase {

    /**
     * A canned 64-hex placeholder hash. Real hashes are session-bound; the
     * exact value doesn't matter for byte-arithmetic tests, only the length
     * (66 bytes including braces).
     */
    private const HASH = '{fb86f5a8a8d4afe1bd5c8a6d5e9b1c5d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1c78d}';

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'apply_filters' )->returnArg( 2 );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_hash_is_66_bytes(): void {
        // The byte arithmetic in sanitize_string assumes a 66-byte token.
        $this->assertSame( 66, strlen( self::HASH ) );
    }

    public function test_sanitize_string_passes_through_clean_input(): void {
        $clean = "INSERT INTO `wp_options` VALUES (1,'siteurl','http://example.com','yes');";
        $this->assertSame( $clean, Mighty_Backup_Placeholder_Repair::sanitize_string( $clean ) );
    }

    public function test_sanitize_string_replaces_bare_token(): void {
        $input    = '/' . self::HASH . 'postname' . self::HASH . '/';
        $expected = '/%postname%/';
        $this->assertSame( $expected, Mighty_Backup_Placeholder_Repair::sanitize_string( $input ) );
    }

    public function test_sanitize_string_recomputes_serialized_length(): void {
        // Original string was "foo%bar" (7 bytes). Corruption inflated to 72 bytes.
        $payload     = 'foo' . self::HASH . 'bar';
        $declared    = strlen( $payload ); // 72
        $serialized  = "s:{$declared}:\"{$payload}\";";

        $repaired = Mighty_Backup_Placeholder_Repair::sanitize_string( $serialized );

        $this->assertSame( 's:7:"foo%bar";', $repaired );
    }

    public function test_sanitize_string_handles_sql_escaped_quotes(): void {
        // mysqldump renders inner serialized quotes as `\"` inside a `'…'`-quoted SQL value.
        $payload    = 'x' . self::HASH . 'y';
        $declared   = strlen( $payload ); // 68
        $sql_chunk  = "(1,'a:1:{i:0;s:{$declared}:\\\"{$payload}\\\";}','yes')";

        $repaired = Mighty_Backup_Placeholder_Repair::sanitize_string( $sql_chunk );

        $this->assertSame( "(1,'a:1:{i:0;s:3:\\\"x%y\\\";}','yes')", $repaired );
    }

    public function test_sanitize_string_handles_multiple_tokens_in_one_serialized_string(): void {
        $payload    = self::HASH . self::HASH;
        $declared   = strlen( $payload ); // 132
        $serialized = "s:{$declared}:\"{$payload}\";";

        $repaired = Mighty_Backup_Placeholder_Repair::sanitize_string( $serialized );

        // Two 66-byte tokens → two 1-byte percents → length 2.
        $this->assertSame( 's:2:"%%";', $repaired );
    }

    public function test_sanitize_string_preserves_non_corrupted_serialized_neighbors(): void {
        // First serialized string is clean; second has a token.
        $clean       = 's:5:"hello";';
        $bad_payload = self::HASH;
        $bad_len     = strlen( $bad_payload );
        $bad         = "s:{$bad_len}:\"{$bad_payload}\";";

        $combined = $clean . $bad;
        $repaired = Mighty_Backup_Placeholder_Repair::sanitize_string( $combined );

        $this->assertSame( 's:5:"hello";s:1:"%";', $repaired );
    }

    public function test_sanitize_string_idempotent(): void {
        $input    = 'foo' . self::HASH . 'bar';
        $first    = Mighty_Backup_Placeholder_Repair::sanitize_string( $input );
        $second   = Mighty_Backup_Placeholder_Repair::sanitize_string( $first );

        $this->assertSame( $first, $second );
    }

    public function test_sanitize_string_does_not_match_short_hex(): void {
        // Only exactly 64 hex chars in braces match. 32-hex shouldn't.
        $short = '{' . str_repeat( 'a', 32 ) . '}';
        $input = "value with {$short} embedded";

        $this->assertSame( $input, Mighty_Backup_Placeholder_Repair::sanitize_string( $input ) );
    }

    public function test_sanitize_string_does_not_match_non_hex_chars(): void {
        // 64 chars of mixed hex+non-hex shouldn't match.
        $bogus = '{' . str_repeat( 'g', 64 ) . '}';
        $input = "value with {$bogus} embedded";

        $this->assertSame( $input, Mighty_Backup_Placeholder_Repair::sanitize_string( $input ) );
    }

    public function test_sanitize_sql_stream_round_trip(): void {
        $hash  = self::HASH;
        $lines = [
            "-- header line\n",
            "SET NAMES utf8mb4;\n",
            "INSERT INTO `wp_options` VALUES (1,'permalink_structure','/{$hash}postname{$hash}/','yes');\n",
            "INSERT INTO `wp_options` VALUES (2,'siteurl','http://example.com','yes');\n",
        ];

        $in_path  = tempnam( sys_get_temp_dir(), 'mb_in_' );
        $out_path = tempnam( sys_get_temp_dir(), 'mb_out_' );

        try {
            file_put_contents( $in_path, implode( '', $lines ) );

            $repaired = Mighty_Backup_Placeholder_Repair::sanitize_sql_file( $in_path, $out_path );

            $this->assertSame( 1, $repaired, 'Expected exactly one line to be repaired' );

            $output = file_get_contents( $out_path );
            $this->assertStringContainsString( "/%postname%/", $output );
            $this->assertStringNotContainsString( $hash, $output );
            $this->assertStringContainsString( "http://example.com", $output ); // Untouched line preserved.
        } finally {
            @unlink( $in_path );
            @unlink( $out_path );
        }
    }

    public function test_sanitize_sql_stream_returns_zero_for_clean_input(): void {
        $in_path  = tempnam( sys_get_temp_dir(), 'mb_in_' );
        $out_path = tempnam( sys_get_temp_dir(), 'mb_out_' );

        try {
            file_put_contents( $in_path, "INSERT INTO t VALUES (1,'foo');\nINSERT INTO t VALUES (2,'bar');\n" );

            $repaired = Mighty_Backup_Placeholder_Repair::sanitize_sql_file( $in_path, $out_path );

            $this->assertSame( 0, $repaired );
            $this->assertSame( file_get_contents( $in_path ), file_get_contents( $out_path ) );
        } finally {
            @unlink( $in_path );
            @unlink( $out_path );
        }
    }

    public function test_serialize_then_sanitize_round_trip_unserializes(): void {
        // Build a real PHP-serialized value with the hash baked in, sanitize,
        // and confirm the result unserializes to a value where the hash has
        // been replaced with '%'. This is the strongest correctness check
        // for length-prefix arithmetic.
        $original = [ 'permalink' => '/' . self::HASH . 'postname' . self::HASH . '/' ];
        $serial   = serialize( $original );

        $repaired = Mighty_Backup_Placeholder_Repair::sanitize_string( $serial );
        $decoded  = unserialize( $repaired );

        $this->assertSame( [ 'permalink' => '/%postname%/' ], $decoded );
    }

    public function test_serialize_object_round_trip(): void {
        $object = (object) [ 'name' => 'before' . self::HASH . 'after' ];
        $serial = serialize( $object );

        $repaired = Mighty_Backup_Placeholder_Repair::sanitize_string( $serial );
        $decoded  = unserialize( $repaired );

        $this->assertSame( 'before%after', $decoded->name );
    }
}
