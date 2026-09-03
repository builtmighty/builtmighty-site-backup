<?php
/**
 * Dependency repair — restores a missing or incomplete bundled vendor/ tree.
 *
 * Mighty Backup ships its own Composer dependencies, so vendor/ should always be
 * present. It can still go missing: a deploy pipeline that filters
 * `wp-content/plugins/**\/vendor`, a truncated unzip during an auto-update, or a
 * site restored from a backup taken before 3.0.1 (which excluded the tree).
 *
 * The sites where that happens are typically ones where we hold WP admin access
 * and nothing else, so the recovery must not require a shell. This downloads the
 * release matching the installed version and copies vendor/ back into place using
 * WordPress's own filesystem abstraction.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Dependency_Repair {

    private const REPO       = 'builtmighty/mighty-backup';
    private const ACTION     = 'mighty_backup_repair_deps';
    private const NOTICE_KEY = 'mighty_backup_repair_result';

    /**
     * Hook the admin-post handler.
     */
    public function init(): void {
        add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_request' ] );
        add_action( 'admin_notices', [ $this, 'render_result_notice' ] );
    }

    /**
     * Nonce-protected URL for the "Repair dependencies" button.
     */
    public static function repair_url(): string {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::ACTION ),
            self::ACTION
        );
    }

    /**
     * admin-post handler. Verifies capability + nonce, repairs, then redirects
     * back with the outcome stashed in a transient (the result is too long for
     * a query arg, and we do not want it replayable on refresh).
     */
    public function handle_request(): void {
        if ( ! mighty_backup_is_authorized_user() || ! current_user_can( 'activate_plugins' ) ) {
            wp_die( esc_html__( 'You are not allowed to repair plugin dependencies.', 'mighty-backup' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::ACTION );

        $result = self::repair();

        set_transient( self::NOTICE_KEY . '_' . get_current_user_id(), $result, 60 );

        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }

    /**
     * Show the outcome of the last repair once, then discard it.
     */
    public function render_result_notice(): void {
        $key    = self::NOTICE_KEY . '_' . get_current_user_id();
        $result = get_transient( $key );
        if ( ! is_array( $result ) ) {
            return;
        }
        delete_transient( $key );

        printf(
            '<div class="notice notice-%s is-dismissible"><p><strong>Mighty Backup:</strong> %s</p></div>',
            $result['success'] ? 'success' : 'error',
            esc_html( $result['message'] )
        );
    }

    /**
     * Download the release matching this install and restore its vendor/ tree.
     *
     * Safe to run when vendor/ is present — it overwrites in place.
     *
     * @return array{success:bool,message:string}
     */
    public static function repair(): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // PHP too old is a different failure with a different fix. Re-downloading
        // the same tree will not help, so say so instead of wasting the round trip.
        if ( PHP_VERSION_ID < 80100 ) {
            return [
                'success' => false,
                'message' => sprintf(
                    'This site runs PHP %s. Mighty Backup requires PHP 8.1 or newer — upgrading PHP is the fix, not repairing dependencies.',
                    PHP_VERSION
                ),
            ];
        }

        $source = self::resolve_download_url();
        if ( is_wp_error( $source ) ) {
            return [ 'success' => false, 'message' => 'Could not find a release to repair from: ' . $source->get_error_message() ];
        }

        $tmp_zip = download_url( $source['url'], 300 );
        if ( is_wp_error( $tmp_zip ) ) {
            return [ 'success' => false, 'message' => 'Download failed: ' . $tmp_zip->get_error_message() ];
        }

        // WP_Filesystem() is required by unzip_file(). Check its RETURN value, not
        // just the global: on a failed connect() it returns false while $wp_filesystem
        // is already instantiated, and it is only past that point that WordPress
        // defines FS_CHMOD_DIR / FS_CHMOD_FILE — which copy_dir() and the mkdir
        // below both rely on.
        $filesystem_ready = WP_Filesystem();
        global $wp_filesystem;
        if ( ! $filesystem_ready || ! $wp_filesystem ) {
            @unlink( $tmp_zip );
            return [ 'success' => false, 'message' => 'WordPress could not initialise its filesystem API, so the plugin directory cannot be written to directly. This host likely needs FTP credentials, or the files are owned by another user.' ];
        }

        $extract_to = trailingslashit( get_temp_dir() ) . 'mighty-backup-repair-' . wp_generate_password( 8, false );
        $unzipped   = unzip_file( $tmp_zip, $extract_to );
        @unlink( $tmp_zip );

        if ( is_wp_error( $unzipped ) ) {
            $wp_filesystem->delete( $extract_to, true );
            return [ 'success' => false, 'message' => 'Could not unpack the release: ' . $unzipped->get_error_message() ];
        }

        $vendor_src = self::locate_vendor( $extract_to, $wp_filesystem );
        if ( $vendor_src === null ) {
            $wp_filesystem->delete( $extract_to, true );
            return [
                'success' => false,
                'message' => sprintf( 'The %s release package did not contain a vendor/ directory. Report this — the release is broken.', $source['label'] ),
            ];
        }

        $vendor_dest = untrailingslashit( MIGHTY_BACKUP_DIR ) . '/vendor';

        // Deliberately overwrite in place rather than delete-then-copy. Deleting
        // first would open a window where the site has no dependencies at all, and
        // a copy that then failed (permissions, disk space) would leave the install
        // strictly worse than before the repair. copy_dir() passes overwrite=true,
        // and we are restoring the tree for the version already installed, so there
        // is nothing meaningful to go stale.
        //
        // Create the destination explicitly: copy_dir() only grew its own
        // mkdir-the-destination guard after WordPress 6.0, which this plugin still
        // supports, and the common repair case is a vendor/ that is entirely gone.
        if ( ! $wp_filesystem->is_dir( $vendor_dest ) && ! $wp_filesystem->mkdir( $vendor_dest, FS_CHMOD_DIR ) ) {
            $wp_filesystem->delete( $extract_to, true );
            return [ 'success' => false, 'message' => 'Could not create the vendor/ directory. Check that the plugin folder is writable by the web server.' ];
        }

        $copied = copy_dir( $vendor_src, $vendor_dest );
        $wp_filesystem->delete( $extract_to, true );

        if ( is_wp_error( $copied ) ) {
            return [ 'success' => false, 'message' => 'Could not write vendor/: ' . $copied->get_error_message() ];
        }

        if ( ! file_exists( $vendor_dest . '/autoload.php' ) ) {
            return [ 'success' => false, 'message' => 'vendor/ was restored but autoload.php is missing. The release package looks incomplete.' ];
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Dependencies restored from the %s release package. Reload this page to confirm the warning has cleared.',
                $source['label']
            ),
        ];
    }

    /**
     * Prefer the release matching the installed version so a repair can never
     * become a silent upgrade; fall back to the latest release if this version
     * has no published release (e.g. running from an untagged build).
     *
     * Within a release, prefer the purpose-built mighty-backup.zip asset and fall
     * back to the source zipball, which every release always has.
     *
     * @return array{url:string,label:string}|WP_Error
     */
    private static function resolve_download_url() {
        $endpoints = [
            MIGHTY_BACKUP_VERSION => 'https://api.github.com/repos/' . self::REPO . '/releases/tags/' . rawurlencode( MIGHTY_BACKUP_VERSION ),
            'latest'              => 'https://api.github.com/repos/' . self::REPO . '/releases/latest',
        ];

        $last_error = null;

        foreach ( $endpoints as $label => $endpoint ) {
            $response = wp_remote_get(
                $endpoint,
                [
                    'timeout' => 20,
                    'headers' => [
                        'Accept'     => 'application/vnd.github+json',
                        'User-Agent' => 'MightyBackup/' . MIGHTY_BACKUP_VERSION,
                    ],
                ]
            );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                continue;
            }
            if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
                $last_error = new WP_Error(
                    'mighty_backup_release_lookup',
                    sprintf( 'GitHub returned HTTP %d for the "%s" release.', (int) wp_remote_retrieve_response_code( $response ), $label )
                );
                continue;
            }

            $release = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $release ) ) {
                $last_error = new WP_Error( 'mighty_backup_release_lookup', 'GitHub returned an unreadable response.' );
                continue;
            }

            $tag = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : $label;

            foreach ( $release['assets'] ?? [] as $asset ) {
                if ( ( $asset['name'] ?? '' ) === 'mighty-backup.zip' && ! empty( $asset['browser_download_url'] ) ) {
                    return [ 'url' => (string) $asset['browser_download_url'], 'label' => $tag ];
                }
            }

            if ( ! empty( $release['zipball_url'] ) ) {
                return [ 'url' => (string) $release['zipball_url'], 'label' => $tag ];
            }
        }

        return $last_error ?: new WP_Error( 'mighty_backup_release_lookup', 'No downloadable release package was found.' );
    }

    /**
     * Find vendor/ inside the extracted package.
     *
     * The release asset nests it under mighty-backup/, while a GitHub source
     * zipball nests it under a commit-stamped directory such as
     * builtmighty-mighty-backup-8008370/, so scan one level rather than guessing.
     */
    private static function locate_vendor( string $root, $wp_filesystem ): ?string {
        $root = trailingslashit( $root );

        if ( $wp_filesystem->is_dir( $root . 'vendor' ) ) {
            return $root . 'vendor';
        }

        foreach ( (array) $wp_filesystem->dirlist( $root ) as $name => $entry ) {
            if ( ( $entry['type'] ?? '' ) === 'd' && $wp_filesystem->is_dir( $root . $name . '/vendor' ) ) {
                return $root . $name . '/vendor';
            }
        }

        return null;
    }
}
