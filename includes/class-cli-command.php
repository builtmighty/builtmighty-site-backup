<?php
/**
 * WP-CLI commands for Mighty Backup.
 *
 * Usage:
 *   wp mighty-backup run [--type=<type>] [--async]
 *   wp mighty-backup status
 *   wp mighty-backup cancel
 *   wp mighty-backup list [--type=<type>]
 *   wp mighty-backup prune
 *   wp mighty-backup test
 *   wp mighty-backup dev-mode [--disable]
 *   wp mighty-backup settings list [--format=<format>] [--show-secrets]
 *   wp mighty-backup settings get <key> [--show-secret]
 *   wp mighty-backup settings set <key> <value>
 *   wp mighty-backup api-key generate
 *   wp mighty-backup api-key show [--raw]
 *   wp mighty-backup api-key delete
 *   wp mighty-backup devcontainer check [--format=<format>]
 *   wp mighty-backup devcontainer update [--branch=<branch>] [--yes]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_CLI_Command {

    /**
     * Run a backup.
     *
     * By default, schedules the backup via Action Scheduler and polls until
     * complete. Use --async to schedule and return immediately.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Type of backup to run.
     * ---
     * default: full
     * options:
     *   - full
     *   - db
     *   - files
     * ---
     *
     * [--async]
     * : Schedule the backup and return immediately without waiting.
     *
     * [--timeout=<seconds>]
     * : Maximum seconds to wait for backup completion (default: 21600 = 6 hours).
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup run
     *     wp mighty-backup run --type=db
     *     wp mighty-backup run --async
     *     wp mighty-backup run --timeout=3600
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function run( $args, $assoc_args ) {
        $type    = $assoc_args['type'] ?? 'full';
        $async   = isset( $assoc_args['async'] );
        $timeout = (int) ( $assoc_args['timeout'] ?? 21600 );

        try {
            $manager = new Mighty_Backup_Manager();
            $manager->schedule( $type, 'cli' );
            WP_CLI::log( "Backup scheduled ({$type})." );

            if ( $async ) {
                WP_CLI::success( 'Backup is running in the background. Use "wp mighty-backup status" to check progress.' );
                return;
            }

            // Poll until complete.
            WP_CLI::log( 'Waiting for backup to complete...' );

            $last_step  = '';
            $start_time = time();
            while ( true ) {
                if ( ( time() - $start_time ) > $timeout ) {
                    WP_CLI::error( "Backup timed out after {$timeout} seconds. The backup may still be running in the background." );
                }

                sleep( 2 );

                // Directly process the next pending backup action.
                $manager->process_next_action();

                $status = $manager->get_status();

                // Show step transitions.
                if ( $status['active'] && isset( $status['message'] ) && $status['message'] !== $last_step ) {
                    WP_CLI::log( '  ' . $status['message'] );
                    $last_step = $status['message'];
                }

                if ( ! $status['active'] ) {
                    break;
                }
            }

            if ( $status['status'] === 'completed' ) {
                if ( ! empty( $status['db_file_size'] ) ) {
                    WP_CLI::log( sprintf( '  Database: %s', size_format( $status['db_file_size'] ) ) );
                }
                if ( ! empty( $status['files_file_size'] ) ) {
                    WP_CLI::log( sprintf( '  Files: %s', size_format( $status['files_file_size'] ) ) );
                }
                $manager->clear_state();
                WP_CLI::success( 'Backup completed successfully.' );
            } else {
                $error = $status['error'] ?? 'Unknown error';
                $manager->clear_state();
                WP_CLI::error( "Backup failed: {$error}" );
            }

        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }

    /**
     * Show the current backup status and next scheduled run.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup status
     */
    public function status( $args, $assoc_args ) {
        $manager   = new Mighty_Backup_Manager();
        $logger    = new Mighty_Backup_Logger();
        $scheduler = new Mighty_Backup_Scheduler();

        // Current backup status.
        $status = $manager->get_status();
        if ( $status['active'] ) {
            WP_CLI::log( 'Backup in progress:' );
            WP_CLI::log( sprintf( '  Status: %s', $status['message'] ) );
            WP_CLI::log( sprintf( '  Progress: %d%%', $status['progress'] ) );
        } else {
            WP_CLI::log( 'No backup currently running.' );
        }

        // Last completed backup.
        $last = $logger->get_last_completed();
        if ( $last ) {
            WP_CLI::log( "\nLast completed backup:" );
            WP_CLI::log( sprintf( '  Date:       %s', $last['completed_at'] ) );
            WP_CLI::log( sprintf( '  Type:       %s', $last['backup_type'] ) );
            WP_CLI::log( sprintf( '  Trigger:    %s', $last['trigger_type'] ) );
            if ( $last['db_file_size'] ) {
                WP_CLI::log( sprintf( '  DB size:    %s', size_format( $last['db_file_size'] ) ) );
            }
            if ( $last['files_file_size'] ) {
                WP_CLI::log( sprintf( '  Files size: %s', size_format( $last['files_file_size'] ) ) );
            }
        } else {
            WP_CLI::log( "\nNo completed backups found." );
        }

        // Dev mode.
        if ( Mighty_Backup_Dev_Mode::is_dev_mode() ) {
            WP_CLI::warning( 'Dev mode is active — scheduled backups are paused (site URL mismatch).' );
            WP_CLI::log( sprintf( '  Live URL:    %s', Mighty_Backup_Dev_Mode::get_live_url() ) );
            WP_CLI::log( sprintf( '  Current URL: %s', network_site_url() ) );
        }

        // Next scheduled run.
        $next = $scheduler->get_next_run();
        if ( $next ) {
            $suffix = Mighty_Backup_Dev_Mode::is_dev_mode() ? ' (paused — dev mode)' : '';
            WP_CLI::log( sprintf( "\nNext scheduled: %s UTC%s", gmdate( 'Y-m-d H:i:s', $next ), $suffix ) );
        } else {
            WP_CLI::log( "\nNo backup scheduled." );
        }
    }

    /**
     * Cancel a running or pending backup.
     *
     * Removes queued backup actions, deletes temporary files, and marks
     * the backup log entry as failed.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup cancel
     */
    public function cancel( $args, $assoc_args ) {
        $manager = new Mighty_Backup_Manager();

        if ( ! $manager->get_state() ) {
            WP_CLI::warning( 'No backup is currently running or pending.' );
            return;
        }

        $cancelled = $manager->cancel();

        if ( $cancelled ) {
            WP_CLI::success( 'Backup cancelled. Temporary files deleted and scheduled actions removed.' );
        } else {
            WP_CLI::warning( 'No active backup to cancel.' );
        }
    }

    /**
     * List backups stored on DigitalOcean Spaces.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Type of backups to list.
     * ---
     * default: all
     * options:
     *   - all
     *   - db
     *   - files
     * ---
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup list
     *     wp mighty-backup list --type=db
     */
    public function list( $args, $assoc_args ) {
        $type = $assoc_args['type'] ?? 'all';

        try {
            $settings = new Mighty_Backup_Settings();
            $client   = new Mighty_Backup_Spaces_Client( $settings );

            $prefixes = [];
            if ( in_array( $type, [ 'all', 'db' ], true ) ) {
                $prefixes['Database backups'] = 'databases/';
            }
            if ( in_array( $type, [ 'all', 'files' ], true ) ) {
                $prefixes['File backups'] = 'files/';
            }

            foreach ( $prefixes as $label => $prefix ) {
                $objects = $client->list_objects( $prefix );

                WP_CLI::log( "\n{$label}:" );
                if ( empty( $objects ) ) {
                    WP_CLI::log( '  (none)' );
                    continue;
                }

                $table_data = array_map( function ( $obj ) {
                    return [
                        'Key'           => basename( $obj['Key'] ),
                        'Size'          => size_format( $obj['Size'] ),
                        'Last Modified' => $obj['LastModified'],
                    ];
                }, $objects );

                WP_CLI\Utils\format_items( 'table', $table_data, [ 'Key', 'Size', 'Last Modified' ] );
            }

        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }

    /**
     * Manually trigger retention cleanup.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup prune
     */
    public function prune( $args, $assoc_args ) {
        try {
            $settings        = new Mighty_Backup_Settings();
            $client          = new Mighty_Backup_Spaces_Client( $settings );
            $retention_count = (int) $settings->get( 'retention_count', 7 );
            $retention       = new Mighty_Backup_Retention_Manager( $client, $retention_count );

            WP_CLI::log( sprintf( 'Running retention cleanup (keeping last %d backups)...', $retention_count ) );

            $result = $retention->prune();

            WP_CLI::success( sprintf(
                'Pruned %d database backup(s) and %d file backup(s).',
                $result['databases_deleted'],
                $result['files_deleted']
            ) );

        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }

    /**
     * Test the DigitalOcean Spaces connection.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup test
     */
    public function test( $args, $assoc_args ) {
        try {
            $settings = new Mighty_Backup_Settings();

            if ( ! $settings->is_configured() ) {
                WP_CLI::error( 'Plugin is not configured. Please enter your DO Spaces credentials in wp-admin.' );
            }

            $client  = new Mighty_Backup_Spaces_Client( $settings );
            $message = $client->test_connection();

            WP_CLI::success( $message );

        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }

    /**
     * Show or change the dev mode status.
     *
     * Dev mode is automatically activated when the site URL changes (e.g.
     * after cloning to staging).  Scheduled backups are paused until dev
     * mode is explicitly disabled.
     *
     * ## OPTIONS
     *
     * [--disable]
     * : Exit dev mode by updating the stored URL to the current site URL
     *   and rescheduling backups.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup dev-mode
     *     wp mighty-backup dev-mode --disable
     *
     * @subcommand dev-mode
     */
    public function dev_mode( $args, $assoc_args ) {
        $live_url    = Mighty_Backup_Dev_Mode::get_live_url();
        $current_url = network_site_url();
        $is_dev      = Mighty_Backup_Dev_Mode::is_dev_mode();

        if ( isset( $assoc_args['disable'] ) ) {
            if ( ! $is_dev ) {
                WP_CLI::success( 'Dev mode is not active — nothing to do.' );
                return;
            }

            update_site_option( Mighty_Backup_Dev_Mode::LIVE_URL_OPTION, $current_url );

            $scheduler = new Mighty_Backup_Scheduler();
            $scheduler->reschedule();

            WP_CLI::success( 'Dev mode disabled. Automatic backups re-enabled.' );
            return;
        }

        // Display status.
        WP_CLI::log( sprintf( 'Dev mode:    %s', $is_dev ? 'ACTIVE' : 'inactive' ) );
        WP_CLI::log( sprintf( 'Live URL:    %s', $live_url ?: '(not set)' ) );
        WP_CLI::log( sprintf( 'Current URL: %s', $current_url ) );

        if ( $is_dev ) {
            WP_CLI::warning( 'Scheduled backups are paused. Run "wp mighty-backup dev-mode --disable" to re-enable.' );
        }
    }
}

/**
 * WP-CLI commands for Mighty Backup settings.
 *
 * Encrypted fields (spaces_secret_key, github_pat) are handled transparently —
 * pass plaintext to `set` and they are encrypted at rest; they are masked in
 * `list` / `get` output unless you pass --show-secret(s).
 */
class Mighty_Backup_Settings_CLI_Command {

    /**
     * List all plugin settings.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     *   - csv
     * ---
     *
     * [--show-secrets]
     * : Decrypt and display encrypted fields. Use with care on shared terminals.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup settings list
     *     wp mighty-backup settings list --format=json
     *     wp mighty-backup settings list --show-secrets
     *
     * @subcommand list
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function list_( $args, $assoc_args ) {
        $format = $assoc_args['format'] ?? 'table';
        $reveal = isset( $assoc_args['show-secrets'] );

        $settings = new Mighty_Backup_Settings();
        $values   = $settings->get_all_display( $reveal );

        $rows = [];
        foreach ( $values as $key => $value ) {
            $rows[] = [
                'key'   => $key,
                'value' => $value,
            ];
        }

        WP_CLI\Utils\format_items( $format, $rows, [ 'key', 'value' ] );
    }

    /**
     * Get a single setting value.
     *
     * ## OPTIONS
     *
     * <key>
     * : The setting key.
     *
     * [--show-secret]
     * : Decrypt and display encrypted fields. Only meaningful for
     *   spaces_secret_key and github_pat.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup settings get spaces_bucket
     *     wp mighty-backup settings get spaces_secret_key --show-secret
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function get( $args, $assoc_args ) {
        $key    = $args[0] ?? '';
        $reveal = isset( $assoc_args['show-secret'] );

        if ( $key === '' ) {
            WP_CLI::error( 'Missing <key>. Run "wp mighty-backup settings list" to see available keys.' );
        }

        try {
            $settings = new Mighty_Backup_Settings();
            $value    = $settings->get_value( $key, $reveal );
            WP_CLI::log( $value );
        } catch ( \InvalidArgumentException $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }

    /**
     * Set a single setting value.
     *
     * Encrypted fields are encrypted transparently. The value is never echoed
     * back to the terminal after a successful write.
     *
     * ## OPTIONS
     *
     * <key>
     * : The setting key.
     *
     * <value>
     * : The new value. Booleans accept 1/0, true/false, yes/no, or on/off.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup settings set spaces_access_key "DO00XXXX..."
     *     wp mighty-backup settings set spaces_secret_key "s3cret-v@lue"
     *     wp mighty-backup settings set schedule_frequency weekly
     *     wp mighty-backup settings set schedule_time 03:30
     *     wp mighty-backup settings set retention_count 14
     *     wp mighty-backup settings set notify_on_failure 1
     *     wp mighty-backup settings set notification_email ops@example.com
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function set( $args, $assoc_args ) {
        $key   = $args[0] ?? '';
        $value = $args[1] ?? null;

        if ( $key === '' || $value === null ) {
            WP_CLI::error( 'Usage: wp mighty-backup settings set <key> <value>' );
        }

        try {
            $settings = new Mighty_Backup_Settings();
            $settings->set_value( $key, (string) $value );

            // Don't echo the value back — some of these are secrets.
            WP_CLI::success( sprintf( 'Updated %s.', $key ) );
        } catch ( \InvalidArgumentException $e ) {
            WP_CLI::error( $e->getMessage() );
        } catch ( \RuntimeException $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }
}

/**
 * WP-CLI commands for the Codespace bootstrap API key (bm_backup_api_key).
 *
 * The API key authenticates the `/wp-json/mighty-backup/v1/codespace-config`
 * REST endpoint. The "bootstrap key" is the base64-encoded form of
 * `{site_url}:{api_key}` — that value is what you paste into the
 * `BM_BOOTSTRAP_KEY` Codespace secret.
 */
class Mighty_Backup_Api_Key_CLI_Command {

    /**
     * Generate (or regenerate) the Codespace bootstrap API key.
     *
     * Regenerating invalidates any existing key — update the BM_BOOTSTRAP_KEY
     * secret in every Codespace that uses it.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup api-key generate
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function generate( $args, $assoc_args ) {
        $existing = Mighty_Backup_Api_Endpoint::get_key();
        if ( ! empty( $existing ) ) {
            WP_CLI::log( 'Regenerating — any existing BM_BOOTSTRAP_KEY secret is now invalid.' );
        }

        Mighty_Backup_Api_Endpoint::generate_key();
        $bootstrap = Mighty_Backup_Api_Endpoint::get_bootstrap_key();

        WP_CLI::success( 'API key generated.' );
        WP_CLI::log( 'Bootstrap key (add as BM_BOOTSTRAP_KEY Codespace secret):' );
        WP_CLI::log( $bootstrap );
    }

    /**
     * Show the current Codespace bootstrap key.
     *
     * ## OPTIONS
     *
     * [--raw]
     * : Print the raw API key instead of the base64 bootstrap form.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup api-key show
     *     wp mighty-backup api-key show --raw
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function show( $args, $assoc_args ) {
        $raw = isset( $assoc_args['raw'] );
        $key = Mighty_Backup_Api_Endpoint::get_key();

        if ( empty( $key ) ) {
            WP_CLI::error( 'No API key has been generated yet. Run "wp mighty-backup api-key generate".' );
        }

        if ( $raw ) {
            WP_CLI::log( $key );
            return;
        }

        WP_CLI::log( Mighty_Backup_Api_Endpoint::get_bootstrap_key() );
    }

    /**
     * Delete the Codespace bootstrap API key.
     *
     * Disables the `/wp-json/mighty-backup/v1/codespace-config` endpoint
     * until a new key is generated.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup api-key delete
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function delete( $args, $assoc_args ) {
        if ( empty( Mighty_Backup_Api_Endpoint::get_key() ) ) {
            WP_CLI::warning( 'No API key is set.' );
            return;
        }

        delete_site_option( Mighty_Backup_Api_Endpoint::API_KEY_OPTION );
        WP_CLI::success( 'API key deleted. The Codespace config endpoint is now disabled.' );
    }

    /**
     * Push BM_BOOTSTRAP_KEY to the configured GitHub repo as an encrypted secret.
     *
     * Encrypts the bootstrap key with the repo's public key (libsodium sealed
     * box) and writes it to either the Codespaces or Actions secret store via
     * the GitHub API. Uses the GitHub owner/repo/PAT saved in the Devcontainer
     * tab.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Which secret store to push to.
     * ---
     * default: codespaces
     * options:
     *   - codespaces
     *   - actions
     * ---
     *
     * [--name=<name>]
     * : Override the secret name. Must be a valid environment variable
     *   identifier.
     * ---
     * default: BM_BOOTSTRAP_KEY
     * ---
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup api-key push-secret
     *     wp mighty-backup api-key push-secret --type=actions
     *     wp mighty-backup api-key push-secret --name=BM_BOOTSTRAP_KEY_STAGING --yes
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function push_secret( $args, $assoc_args ) {
        $type = strtolower( (string) ( $assoc_args['type'] ?? 'codespaces' ) );
        $name = (string) ( $assoc_args['name'] ?? 'BM_BOOTSTRAP_KEY' );

        if ( ! in_array( $type, [ 'codespaces', 'actions' ], true ) ) {
            WP_CLI::error( 'Invalid --type. Must be "codespaces" or "actions".' );
        }

        WP_CLI::confirm(
            sprintf( 'Push %s to the configured GitHub repo as a %s secret?', $name, $type ),
            $assoc_args
        );

        try {
            $manager = new Mighty_Devcontainer_Manager( new Mighty_Backup_Settings() );
            $result  = $manager->push_bootstrap_secret( $type, $name );
        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }

        $verb = $result['created'] ? 'created' : 'updated';

        WP_CLI::success( sprintf( 'Secret %s (%s) in %s/%s.', $verb, $result['secret_name'], $result['owner'], $result['repo'] ) );
        WP_CLI::log( sprintf( 'Type:   %s', $result['type'] ) );
        WP_CLI::log( sprintf( 'URL:    %s', $result['secret_url'] ) );
    }
}

/**
 * WP-CLI commands for managing the .devcontainer config via the GitHub API.
 *
 * Mirrors the Devcontainer tab in the admin UI: check the repo's current
 * devcontainer.json version against the global template, and create a pull
 * request to install or update when needed.
 */
class Mighty_Backup_Devcontainer_CLI_Command {

    /**
     * Check the repo's .devcontainer version against the global template.
     *
     * Shows the current version (if installed), the latest available version
     * from the global template, and the list of branches available in the
     * target repository.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Non-table formats emit the full raw payload including
     *   the branch list.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup devcontainer check
     *     wp mighty-backup devcontainer check --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function check( $args, $assoc_args ) {
        $format = $assoc_args['format'] ?? 'table';

        try {
            $manager = new Mighty_Devcontainer_Manager( new Mighty_Backup_Settings() );
            $result  = $manager->check_version();
        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }

        if ( $format !== 'table' ) {
            WP_CLI::print_value( $result, [ 'format' => $format ] );
            return;
        }

        $labels = [
            'up_to_date'    => 'Up to date',
            'outdated'      => 'Out of date',
            'not_installed' => 'Not installed',
        ];
        $status_label = $labels[ $result['status'] ] ?? $result['status'];

        WP_CLI::log( sprintf( 'Status:         %s', $status_label ) );
        WP_CLI::log( sprintf( 'Current:        %s', $result['current'] ?? '(none)' ) );
        WP_CLI::log( sprintf( 'Latest:         v%s', $result['latest'] ) );
        WP_CLI::log( sprintf( 'Default branch: %s', $result['default_branch'] ) );

        if ( ! empty( $result['branches'] ) ) {
            WP_CLI::log( sprintf( 'Branches (%d):  %s', count( $result['branches'] ), implode( ', ', $result['branches'] ) ) );
        }

        if ( $result['status'] === 'up_to_date' ) {
            WP_CLI::success( 'Devcontainer is up to date.' );
        } else {
            WP_CLI::warning( 'Run "wp mighty-backup devcontainer update" to create an update PR.' );
        }
    }

    /**
     * Create a PR that installs or updates the .devcontainer directory.
     *
     * Fails if the devcontainer is already up to date.
     *
     * ## OPTIONS
     *
     * [--branch=<branch>]
     * : Target branch for the PR. Defaults to the repository's default branch.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp mighty-backup devcontainer update
     *     wp mighty-backup devcontainer update --branch=develop
     *     wp mighty-backup devcontainer update --yes
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function update( $args, $assoc_args ) {
        $branch = $assoc_args['branch'] ?? '';

        $target_label = $branch !== '' ? $branch : 'the repository default branch';
        WP_CLI::confirm( sprintf( 'Create a PR to update .devcontainer (base: %s)?', $target_label ), $assoc_args );

        try {
            $manager = new Mighty_Devcontainer_Manager( new Mighty_Backup_Settings() );
            $result  = $manager->install_or_update( $branch );
        } catch ( \Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }

        WP_CLI::success( 'Pull request created.' );
        WP_CLI::log( sprintf( 'Branch: %s', $result['branch'] ) );
        WP_CLI::log( sprintf( 'PR URL: %s', $result['pr_url'] ) );
    }
}
