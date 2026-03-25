<?php
/**
 * Tests for Mighty_Backup_Logger — table name and DB interaction.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_wpdb( string $prefix = 'wp_' ): object {
        $wpdb              = new stdClass();
        $wpdb->base_prefix = $prefix;
        return $wpdb;
    }

    public function test_get_table_name_uses_wp_prefix(): void {
        global $wpdb;
        $wpdb = $this->make_wpdb( 'wp_' );

        $logger = new Mighty_Backup_Logger();
        $this->assertSame( 'wp_bm_backup_log', $logger->get_table_name() );
    }

    public function test_get_table_name_uses_custom_prefix(): void {
        global $wpdb;
        $wpdb = $this->make_wpdb( 'mysite_' );

        $logger = new Mighty_Backup_Logger();
        $this->assertSame( 'mysite_bm_backup_log', $logger->get_table_name() );
    }

    public function test_start_inserts_running_record(): void {
        global $wpdb;
        $wpdb              = new stdClass();
        $wpdb->base_prefix = 'wp_';
        $wpdb->insert_id   = 42;

        Functions\when( 'current_time' )->justReturn( '2026-01-01 03:00:00' );

        $inserted_data   = null;
        $wpdb->insert = function ( $table, $data, $formats ) use ( &$inserted_data ) {
            $inserted_data = $data;
        };

        $logger = new Mighty_Backup_Logger();

        // Use reflection to call start() with mocked $wpdb->insert as a closure.
        // The real method calls $wpdb->insert() as a method — stub it on the object.
        $mock_wpdb = $this->getMockBuilder( stdClass::class )
                          ->addMethods( [ 'insert' ] )
                          ->getMock();
        $mock_wpdb->base_prefix = 'wp_';
        $mock_wpdb->insert_id   = 99;

        $mock_wpdb->expects( $this->once() )
                  ->method( 'insert' )
                  ->with(
                      $this->equalTo( 'wp_bm_backup_log' ),
                      $this->callback( function ( $data ) {
                          return $data['backup_type'] === 'full'
                              && $data['trigger_type'] === 'scheduled'
                              && $data['status'] === 'running';
                      } )
                  );

        $wpdb   = $mock_wpdb;
        $logger = new Mighty_Backup_Logger();
        $id     = $logger->start( 'full', 'scheduled' );

        $this->assertSame( 99, $id );
    }

    public function test_complete_updates_status_to_completed(): void {
        global $wpdb;

        Functions\when( 'current_time' )->justReturn( '2026-01-01 03:05:00' );

        $mock_wpdb = $this->getMockBuilder( stdClass::class )
                          ->addMethods( [ 'update' ] )
                          ->getMock();
        $mock_wpdb->base_prefix = 'wp_';

        $mock_wpdb->expects( $this->once() )
                  ->method( 'update' )
                  ->with(
                      $this->equalTo( 'wp_bm_backup_log' ),
                      $this->callback( function ( $data ) {
                          return $data['status'] === 'completed'
                              && isset( $data['completed_at'] );
                      } ),
                      $this->equalTo( [ 'id' => 5 ] )
                  );

        $wpdb   = $mock_wpdb;
        $logger = new Mighty_Backup_Logger();
        $logger->complete( 5 );
    }

    public function test_fail_updates_status_to_failed_with_message(): void {
        global $wpdb;

        Functions\when( 'current_time' )->justReturn( '2026-01-01 03:05:00' );

        $mock_wpdb = $this->getMockBuilder( stdClass::class )
                          ->addMethods( [ 'update' ] )
                          ->getMock();
        $mock_wpdb->base_prefix = 'wp_';

        $error_message = 'Database export failed: connection refused';

        $mock_wpdb->expects( $this->once() )
                  ->method( 'update' )
                  ->with(
                      $this->equalTo( 'wp_bm_backup_log' ),
                      $this->callback( function ( $data ) use ( $error_message ) {
                          return $data['status'] === 'failed'
                              && $data['error_message'] === $error_message;
                      } ),
                      $this->equalTo( [ 'id' => 7 ] )
                  );

        $wpdb   = $mock_wpdb;
        $logger = new Mighty_Backup_Logger();
        $logger->fail( 7, $error_message );
    }
}
