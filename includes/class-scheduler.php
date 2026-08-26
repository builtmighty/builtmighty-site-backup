<?php
/**
 * Backup scheduler — manages WP-Cron events for automated backups.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Scheduler {

    private const CRON_HOOK           = 'mighty_backup_scheduled';
    private const RETENTION_CRON_HOOK = 'mighty_backup_retention';
    public const  LAST_RETENTION_RUN  = 'bm_last_retention_run';

    /**
     * Hook into WordPress to listen for the cron events.
     */
    public function init(): void {
        add_action( self::CRON_HOOK, [ $this, 'run_scheduled_backup' ] );
        add_action( self::RETENTION_CRON_HOOK, [ $this, 'run_retention' ] );
        // WordPress core ships 'hourly', 'twicedaily', 'daily' only; without
        // this filter wp_schedule_event('weekly', ...) silently returns false
        // and the operator gets zero backups despite selecting Weekly.
        add_filter( 'cron_schedules', [ $this, 'register_weekly_recurrence' ] );
    }

    /**
     * Register the 'weekly' cron recurrence. WordPress core has no built-in
     * weekly schedule. Reuses WEEK_IN_SECONDS (604,800) to stay consistent
     * with core's interval naming.
     *
     * @param array $schedules Existing wp-cron schedules keyed by name.
     */
    public function register_weekly_recurrence( array $schedules ): array {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'mighty-backup' ),
            ];
        }
        return $schedules;
    }

    /**
     * Schedule both cron events (backup + retention).
     */
    public function schedule(): void {
        $switched = $this->maybe_switch_to_main_site();

        try {
            // Backup cron — frequency configurable via settings.
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                $settings  = new Mighty_Backup_Settings();
                $frequency = $settings->get( 'schedule_frequency', 'daily' );
                $time      = $settings->get( 'schedule_time', '03:00' );
                $day       = $settings->get( 'schedule_day', 'monday' );

                $next_run = $this->calculate_next_run( $time, $frequency, $day );

                wp_schedule_event( $next_run, $frequency, self::CRON_HOOK );
            }

            // Retention cron — always daily, decoupled from backup success so a
            // streak of failed backups doesn't let old objects accumulate on
            // Spaces. Fires a few hours after the typical backup window.
            if ( ! wp_next_scheduled( self::RETENTION_CRON_HOOK ) ) {
                $retention_next = $this->next_time_in_wp_tz( 6, 0 );
                wp_schedule_event( $retention_next, 'daily', self::RETENTION_CRON_HOOK );
            }
        } finally {
            if ( $switched ) {
                restore_current_blog();
            }
        }
    }

    /**
     * Remove both scheduled cron events.
     */
    public function unschedule(): void {
        $switched = $this->maybe_switch_to_main_site();

        try {
            foreach ( [ self::CRON_HOOK, self::RETENTION_CRON_HOOK ] as $hook ) {
                $timestamp = wp_next_scheduled( $hook );
                if ( $timestamp ) {
                    wp_unschedule_event( $timestamp, $hook );
                }
                wp_clear_scheduled_hook( $hook );
            }
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
        if ( Mighty_Backup_Dev_Mode::is_dev_mode() ) {
            error_log( 'Mighty Backup: Scheduled backup skipped — dev mode active (site URL mismatch).' );
            return;
        }

        try {
            $manager = new Mighty_Backup_Manager();
            $manager->schedule( 'full', 'scheduled' );
        } catch ( \Exception $e ) {
            error_log( 'Mighty Backup: Failed to schedule backup — ' . $e->getMessage() );
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
     * Run retention prune (called by WP-Cron, daily).
     *
     * Independent of the backup chain — exists so that a streak of failed
     * backups doesn't let old objects accumulate on Spaces. The in-chain
     * `step_cleanup` is still the optimal moment to prune (right after a fresh
     * backup succeeds); this is the safety net for everything else.
     *
     * Records the result in the LAST_RETENTION_RUN site option for the
     * Schedule tab "Retention last ran" display.
     */
    public function run_retention(): void {
        // On multisite, only the main site runs the cron.
        if ( is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
            return;
        }

        $settings        = new Mighty_Backup_Settings();
        $retention_count = (int) $settings->get( 'retention_count', 7 );

        $result = [
            'timestamp'         => time(),
            'databases_deleted' => 0,
            'files_deleted'     => 0,
            'error'             => null,
        ];

        try {
            if ( ! mighty_backup_has_sdk() ) {
                throw new \RuntimeException( 'AWS SDK not available — cannot reach DigitalOcean Spaces.' );
            }

            $client    = new Mighty_Backup_Spaces_Client( $settings );
            $retention = new Mighty_Backup_Retention_Manager(
                $client,
                $retention_count,
                $settings->get_object_stem()
            );
            $pruned    = $retention->prune();

            $result['databases_deleted'] = (int) ( $pruned['databases_deleted'] ?? 0 );
            $result['files_deleted']     = (int) ( $pruned['files_deleted'] ?? 0 );

            Mighty_Backup_Log_Stream::add( sprintf(
                'Retention cron pruned %d database backup(s) and %d file backup(s) (keeping %d).',
                $result['databases_deleted'],
                $result['files_deleted'],
                $retention_count
            ) );

            // Sweep multipart uploads older than 24h whose abort got missed
            // (worker SIGKILL during upload, lifecycle rule absent or stale).
            // Each one is otherwise charged for storage indefinitely.
            $swept = $client->sweep_orphan_multiparts( 24, $settings->get_object_stem() );
            $result['multiparts_aborted'] = (int) ( $swept['aborted'] ?? 0 );
            if ( ! empty( $swept['aborted'] ) ) {
                Mighty_Backup_Log_Stream::add( sprintf(
                    'Retention cron aborted %d orphan multipart upload(s).',
                    $swept['aborted']
                ) );
            }
            if ( ! empty( $swept['errors'] ) ) {
                foreach ( $swept['errors'] as $err ) {
                    Mighty_Backup_Log_Stream::add( 'Multipart sweep warning: ' . $err );
                }
            }

            // Prune the local history table so it doesn't grow unbounded
            // (a busy install accumulates ~365 rows/year). Default 90 days,
            // filterable via mighty_backup_history_retention_days.
            $history_days = (int) apply_filters( 'mighty_backup_history_retention_days', 90 );
            $logger       = new Mighty_Backup_Logger();
            $pruned_rows  = $logger->prune_history( $history_days );
            $result['history_pruned'] = $pruned_rows;
            if ( $pruned_rows > 0 ) {
                Mighty_Backup_Log_Stream::add( sprintf(
                    'Retention cron pruned %d history row(s) older than %d days.',
                    $pruned_rows,
                    $history_days
                ) );
            }

            // Stale completed-state sweep. After a successful backup,
            // STATE_OPTION lingers as 'completed' until the next schedule()
            // call clears it (so the admin UI can show the post-run report).
            // If the operator never triggers another backup, it sits forever.
            // 72h sweep is a reasonable upper bound on "user has seen the
            // post-run report" without trampling a still-relevant report.
            $current_state = get_site_option( 'bm_backup_current_state' );
            if ( is_array( $current_state ) && ( $current_state['status'] ?? '' ) === 'completed' ) {
                $started_ts = isset( $current_state['started_at'] ) ? strtotime( (string) $current_state['started_at'] ) : false;
                if ( $started_ts !== false && ( time() - $started_ts ) > ( 72 * 3600 ) ) {
                    delete_site_option( 'bm_backup_current_state' );
                    Mighty_Backup_Log_Stream::add( 'Retention cron cleared stale completed STATE_OPTION (>72h).' );
                }
            }
        } catch ( \Throwable $e ) {
            $result['error'] = $e->getMessage();
            Mighty_Backup_Log_Stream::add( 'Retention cron failed: ' . $e->getMessage() );
        }

        update_site_option( self::LAST_RETENTION_RUN, $result );
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
     * Calculate the next run timestamp based on the configured time and
     * recurrence. For 'weekly', honors schedule_day (a weekday name); for
     * 'daily'/'twicedaily', just picks today or tomorrow at HH:MM.
     *
     * Uses wp_timezone() and DateTimeImmutable to handle DST correctly —
     * the previous current_time('timestamp') + strtotime() combination
     * was deprecated since WP 5.3 and could shift by an hour twice a year.
     *
     * @param string $time      HH:MM in the site's configured timezone.
     * @param string $frequency 'hourly' | 'twicedaily' | 'daily' | 'weekly'.
     * @param string $day       Weekday name for 'weekly' ('monday'..'sunday').
     * @return int UTC Unix timestamp.
     */
    private function calculate_next_run( string $time, string $frequency = 'daily', string $day = 'monday' ): int {
        $parts = explode( ':', $time );
        $hour  = max( 0, min( 23, (int) ( $parts[0] ?? 3 ) ) );
        $min   = max( 0, min( 59, (int) ( $parts[1] ?? 0 ) ) );

        if ( $frequency === 'weekly' ) {
            return $this->next_weekday_in_wp_tz( $day, $hour, $min );
        }

        return $this->next_time_in_wp_tz( $hour, $min );
    }

    /**
     * Return the next future UTC timestamp at HH:MM in the WP timezone.
     * If HH:MM hasn't passed today, returns today; otherwise tomorrow.
     */
    private function next_time_in_wp_tz( int $hour, int $min ): int {
        $tz   = wp_timezone();
        $now  = new \DateTimeImmutable( 'now', $tz );
        $next = $now->setTime( $hour, $min, 0 );
        if ( $next <= $now ) {
            $next = $next->modify( '+1 day' );
        }
        return $next->getTimestamp();
    }

    /**
     * Return the next future UTC timestamp at HH:MM on the named weekday in
     * the WP timezone. Used by the 'weekly' recurrence so schedule_day is
     * actually honored (it was previously stored but silently dropped).
     *
     * @param string $day_name 'monday'..'sunday' (case-insensitive).
     */
    private function next_weekday_in_wp_tz( string $day_name, int $hour, int $min ): int {
        static $map = [
            'sunday'    => 0,
            'monday'    => 1,
            'tuesday'   => 2,
            'wednesday' => 3,
            'thursday'  => 4,
            'friday'    => 5,
            'saturday'  => 6,
        ];
        $target = $map[ strtolower( $day_name ) ] ?? 1; // default Monday

        $tz   = wp_timezone();
        $now  = new \DateTimeImmutable( 'now', $tz );
        $current_dow = (int) $now->format( 'w' );        // 0=Sun..6=Sat
        $days_ahead  = ( $target - $current_dow + 7 ) % 7;

        $next = $now->setTime( $hour, $min, 0 );
        if ( $days_ahead > 0 ) {
            $next = $next->modify( "+{$days_ahead} days" );
        } elseif ( $next <= $now ) {
            // Today is the target day but HH:MM has passed — go next week.
            $next = $next->modify( '+7 days' );
        }
        return $next->getTimestamp();
    }
}
