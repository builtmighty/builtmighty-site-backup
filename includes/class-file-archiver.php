<?php
/**
 * File archiver — creates tar.gz archive of the WordPress install, excluding uploads.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BM_Backup_File_Archiver {

    /**
     * Default directory patterns to exclude from the archive.
     */
    private const DEFAULT_EXCLUSIONS = [
        'wp-content/uploads',
        'wp-content/cache',
        'wp-content/upgrade',
        'wp-content/backups',
        'wp-content/backup-db',
        '.git',
        'node_modules',
    ];

    private BM_Backup_Settings $settings;

    public function __construct( BM_Backup_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Create a tar.gz archive of the WordPress install.
     *
     * @param string $output_path Absolute path for the output .tar.gz file.
     * @return int File size in bytes.
     * @throws \Exception On archive failure.
     */
    public function archive( string $output_path ): int {
        $wp_root = $this->get_wp_root();

        if ( ! is_dir( $wp_root ) ) {
            throw new \Exception( "WordPress root directory not found: {$wp_root}" );
        }

        $exclusions = $this->get_exclusions();

        if ( $this->can_use_shell_tar() ) {
            $this->archive_with_tar( $output_path, $wp_root, $exclusions );
        } else {
            $this->archive_with_phardata( $output_path, $wp_root, $exclusions );
        }

        $size = filesize( $output_path );
        if ( $size === false || $size === 0 ) {
            throw new \Exception( 'Archive creation failed — output file is empty.' );
        }

        return $size;
    }

    /**
     * Check if shell tar is available.
     */
    private function can_use_shell_tar(): bool {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }

        // Check if exec is disabled.
        $disabled = explode( ',', ini_get( 'disable_functions' ) );
        $disabled = array_map( 'trim', $disabled );
        if ( in_array( 'exec', $disabled, true ) ) {
            return false;
        }

        exec( 'which tar 2>/dev/null', $output, $return_code );
        return $return_code === 0;
    }

    /**
     * Create archive using shell tar command (preferred — fast, low memory).
     */
    private function archive_with_tar( string $output_path, string $wp_root, array $exclusions ): void {
        $exclude_args = '';
        foreach ( $exclusions as $pattern ) {
            $escaped = escapeshellarg( $pattern );
            $exclude_args .= " --exclude={$escaped}";
        }

        $output_escaped = escapeshellarg( $output_path );
        $root_escaped   = escapeshellarg( $wp_root );

        $command = "tar -czf {$output_escaped}{$exclude_args} -C {$root_escaped} . 2>&1";

        exec( $command, $output, $return_code );

        if ( $return_code !== 0 ) {
            $error = implode( "\n", $output );
            throw new \Exception( "tar command failed (exit {$return_code}): {$error}" );
        }
    }

    /**
     * Create archive using PHP PharData (fallback for hosts without exec).
     */
    private function archive_with_phardata( string $output_path, string $wp_root, array $exclusions ): void {
        // PharData needs a .tar path first, then we compress.
        $tar_path = preg_replace( '/\.gz$/', '', $output_path );
        if ( $tar_path === $output_path ) {
            $tar_path = $output_path . '.tar';
        }

        // Remove existing files to avoid PharData errors.
        if ( file_exists( $tar_path ) ) {
            unlink( $tar_path );
        }
        if ( file_exists( $output_path ) ) {
            unlink( $output_path );
        }

        $phar = new PharData( $tar_path );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $wp_root,
                RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            $real_path     = $file->getRealPath();
            $relative_path = ltrim( str_replace( $wp_root, '', $real_path ), '/' );

            if ( $this->is_excluded( $relative_path, $exclusions ) ) {
                continue;
            }

            if ( $file->isDir() ) {
                $phar->addEmptyDir( $relative_path );
            } elseif ( $file->isFile() && $file->isReadable() ) {
                $phar->addFile( $real_path, $relative_path );
            }
        }

        // Compress to .tar.gz.
        $phar->compress( Phar::GZ );

        // Clean up the uncompressed .tar.
        if ( file_exists( $tar_path ) ) {
            unlink( $tar_path );
        }
    }

    /**
     * Check if a relative path matches any exclusion pattern.
     */
    private function is_excluded( string $relative_path, array $exclusions ): bool {
        foreach ( $exclusions as $pattern ) {
            if ( str_starts_with( $relative_path, $pattern ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the combined exclusion list (defaults + user-configured).
     */
    private function get_exclusions(): array {
        $exclusions = self::DEFAULT_EXCLUSIONS;

        $extra = $this->settings->get( 'extra_exclusions', '' );
        if ( ! empty( $extra ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $extra ) ) );
            $exclusions = array_merge( $exclusions, $lines );
        }

        return array_unique( $exclusions );
    }

    /**
     * Get the WordPress root directory.
     */
    private function get_wp_root(): string {
        return untrailingslashit( ABSPATH );
    }
}
