<?php
/**
 * Tests for Mighty_Backup_Database_Exporter — stderr filtering and binary selection.
 *
 * Regression guard for the MariaDB false-failure bug (mysqldump shim prints a
 * deprecation warning to stderr on every call; we must not treat that as failure).
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class DatabaseExporterTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'apply_filters' )->returnArg( 2 );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private method via reflection.
     *
     * Keeps production visibility private while still allowing unit tests to
     * exercise the helpers directly.
     */
    private function invoke( object $instance, string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( $instance, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $instance, $args );
    }

    public function test_filter_dump_stderr_strips_mariadb_deprecation(): void {
        $exporter = new Mighty_Backup_Database_Exporter();

        $input  = "mysqldump: Deprecated program name. It will be removed in a future release, use '/usr/bin/mariadb-dump' instead\n";
        $result = $this->invoke( $exporter, 'filter_dump_stderr', [ $input ] );

        $this->assertSame( '', $result );
    }

    public function test_filter_dump_stderr_preserves_real_errors(): void {
        $exporter = new Mighty_Backup_Database_Exporter();

        $input = "mysqldump: Deprecated program name. It will be removed in a future release, use '/usr/bin/mariadb-dump' instead\n"
               . "mysqldump: Got error: 1045: Access denied for user 'x'@'y' when trying to connect";

        $result = $this->invoke( $exporter, 'filter_dump_stderr', [ $input ] );

        // Deprecation line is gone; real error is preserved.
        $this->assertStringNotContainsString( 'Deprecated program name', $result );
        $this->assertStringContainsString( 'Access denied', $result );
    }

    public function test_filter_dump_stderr_handles_empty_input(): void {
        $exporter = new Mighty_Backup_Database_Exporter();

        $this->assertSame( '', $this->invoke( $exporter, 'filter_dump_stderr', [ '' ] ) );
    }

    public function test_filter_dump_stderr_handles_mixed_lines(): void {
        $exporter = new Mighty_Backup_Database_Exporter();

        $input = "Warning: Using a password on the command line interface can be insecure.\n"
               . "mysqldump: Deprecated program name. It will be removed in a future release, use '/usr/bin/mariadb-dump' instead\n"
               . "Warning: Skipping the data of table mysql.event. Specify the --events option explicitly.";

        $result = $this->invoke( $exporter, 'filter_dump_stderr', [ $input ] );

        $this->assertStringNotContainsString( 'Deprecated program name', $result );
        $this->assertStringContainsString( 'Using a password on the command line', $result );
        $this->assertStringContainsString( 'Skipping the data of table mysql.event', $result );
    }

    public function test_filter_dump_stderr_trims_surrounding_whitespace(): void {
        $exporter = new Mighty_Backup_Database_Exporter();

        $input = "\n\nmysqldump: Deprecated program name. It will be removed in a future release, use '/usr/bin/mariadb-dump' instead\n\n\n";
        $this->assertSame( '', $this->invoke( $exporter, 'filter_dump_stderr', [ $input ] ) );
    }

    public function test_get_dump_binary_returns_a_supported_binary(): void {
        $exporter = new Mighty_Backup_Database_Exporter();

        $bin = $this->invoke( $exporter, 'get_dump_binary' );

        // The exact binary depends on what's installed on the test host — we
        // just verify it's one of the two supported values.
        $this->assertContains( $bin, [ 'mariadb-dump', 'mysqldump' ] );
    }
}
