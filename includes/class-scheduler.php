<?php
/**
 * Backup scheduler — manages WP-Cron events for automated backups.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BM_Backup_Scheduler {

    private const CRON_HOOK = 'bm_backup_scheduled';

    /**
     * Hook into WordPress to listen for the cron event.
     */
    public function init(): void {
        add_action( self::CRON_HOOK, [ $this, 'run_scheduled_backup' ] );
    }

    /**
     * Schedule the backup cron event.
     */
    public function schedule(): void {
        $switched = $this->maybe_switch_to_main_site();

        try {
            if ( wp_next_scheduled( self::CRON_HOOK ) ) {
                return;
            }

            $settings  = new BM_Backup_Settings();
            $frequency = $settings->get( 'schedule_frequency', 'daily' );
            $time      = $settings->get( 'schedule_time', '03:00' );

            $next_run = $this->calculate_next_run( $time );

            wp_schedule_event( $next_run, $frequency, self::CRON_HOOK );
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
    }

    /**
     * Remove the scheduled cron event.
     */
    public function unschedule(): void {
        $switched = $this->maybe_switch_to_main_site();

        try {
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, self::CRON_HOOK );
            }
            wp_clear_scheduled_hook( self::CRON_HOOK );
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
    }

    /**
     * Reschedule (called when settings change).
     */
    public function reschedule(): void {
        $this->unschedule();
        $this->schedule();
    }

    /**
     * Run the scheduled backup (called by WP-Cron).
     *
     * This just schedules the Action Scheduler chain — the actual work
     * happens in separate, time-limited background actions.
     */
    public function run_scheduled_backup(): void {
        // On multisite, only the main site should run scheduled backups.
        if ( is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
            return;
        }

        // Dev mode: skip scheduled backups when the site URL has changed.
        if ( BM_Backup_Dev_Mode::is_dev_mode() ) {
            error_log( 'BM Site Backup: Scheduled backup skipped — dev mode active (site URL mismatch).' );
            return;
        }

        try {
            $manager = new BM_Backup_Manager();
            $manager->schedule( 'full', 'scheduled' );
        } catch ( \Exception $e ) {
            error_log( 'BM Site Backup: Failed to schedule backup — ' . $e->getMessage() );
        }
    }

    /**
     * Get the next scheduled run time.
     *
     * @return int|false Unix timestamp or false if not scheduled.
     */
    public function get_next_run() {
        return wp_next_scheduled( self::CRON_HOOK );
    }

    /**
     * On multisite, switch to the main site so cron events are registered there.
     *
     * @return bool True if a blog switch occurred and restore_current_blog() is needed.
     */
    private function maybe_switch_to_main_site(): bool {
        if ( is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
            switch_to_blog( get_main_site_id() );
            return true;
        }
        return false;
    }

    /**
     * Calculate the next run timestamp based on the configured time.
     *
     * @param string $time Time in HH:MM format.
     * @return int Unix timestamp.
     */
    private function calculate_next_run( string $time ): int {
        $parts = explode( ':', $time );
        $hour  = (int) ( $parts[0] ?? 3 );
        $min   = (int) ( $parts[1] ?? 0 );

        // Use WordPress timezone (Settings > General).
        $now  = current_time( 'timestamp' );
        $today = strtotime( "today {$hour}:{$min}", $now );

        // If the time has already passed today, schedule for tomorrow.
        if ( $today <= $now ) {
            return strtotime( 'tomorrow ' . sprintf( '%02d:%02d', $hour, $min ), $now );
        }

        return $today;
    }
}
