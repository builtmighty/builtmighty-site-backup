<?php
/**
 * Tests for Mighty_Backup_Manager — state management and cancellation.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class BackupManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'get_site_option' )->justReturn( null );
        Functions\when( 'update_site_option' )->justReturn( true );
        Functions\when( 'delete_site_option' )->justReturn( true );
        Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 03:00:00' );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'apply_filters' )->returnArg( 2 ); // Return the default value.
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_cancel_returns_false_when_no_backup_running(): void {
        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->cancel() );
    }

    public function test_cancel_returns_false_for_completed_state(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status' => 'completed',
        ] );

        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->cancel() );
    }

    public function test_cancel_returns_false_for_failed_state(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status' => 'failed',
        ] );

        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->cancel() );
    }

    public function test_cancel_returns_true_for_running_backup(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status'           => 'running',
            'log_id'           => null,
            'db_local_path'    => null,
            'files_local_path' => null,
        ] );

        $manager = new Mighty_Backup_Manager();
        $this->assertTrue( $manager->cancel() );
    }

    public function test_cancel_returns_true_for_pending_backup(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status'           => 'pending',
            'log_id'           => null,
            'db_local_path'    => null,
            'files_local_path' => null,
        ] );

        $manager = new Mighty_Backup_Manager();
        $this->assertTrue( $manager->cancel() );
    }

    public function test_get_status_returns_idle_when_no_state(): void {
        $manager = new Mighty_Backup_Manager();
        $status  = $manager->get_status();

        $this->assertFalse( $status['active'] );
        $this->assertSame( 'idle', $status['status'] );
    }

    public function test_get_status_returns_active_when_running(): void {
        Functions\when( 'get_site_option' )->justReturn( [
            'status'       => 'running',
            'type'         => 'full',
            'trigger'      => 'manual',
            'timestamp'    => '2026-01-01-030000',
            'current_step' => 'export_db',
            'step_index'   => 1,
            'steps'        => [ 'start', 'export_db', 'archive_files', 'upload_db', 'upload_files', 'cleanup' ],
            'db_file_size'    => null,
            'files_file_size' => null,
            'error'        => null,
            'started_at'   => '2026-01-01 03:00:00',
        ] );

        $manager = new Mighty_Backup_Manager();
        $status  = $manager->get_status();

        $this->assertTrue( $status['active'] );
        $this->assertSame( 'running', $status['status'] );
        $this->assertSame( 'export_db', $status['current_step'] );
        $this->assertSame( 'Exporting database', $status['step_label'] );
    }

    public function test_is_running_returns_false_when_no_state(): void {
        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->is_running() );
    }

    public function test_is_running_returns_false_for_completed_state(): void {
        Functions\when( 'get_site_option' )->justReturn( [ 'status' => 'completed' ] );
        $manager = new Mighty_Backup_Manager();
        $this->assertFalse( $manager->is_running() );
    }

    public function test_is_running_returns_true_when_running(): void {
        Functions\when( 'get_site_option' )->justReturn( [ 'status' => 'running' ] );
        $manager = new Mighty_Backup_Manager();
        $this->assertTrue( $manager->is_running() );
    }

    public function test_get_state_returns_null_when_no_option(): void {
        $manager = new Mighty_Backup_Manager();
        $this->assertNull( $manager->get_state() );
    }

    public function test_clear_state_does_not_throw(): void {
        // clear_state() calls delete_site_option — confirm it completes without error.
        $manager = new Mighty_Backup_Manager();
        $manager->clear_state();
        $this->assertTrue( true ); // Reached without exception.
    }
}
