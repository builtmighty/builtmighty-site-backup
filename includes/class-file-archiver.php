<?php
/**
 * File archiver — creates tar.gz archive of the WordPress install, excluding uploads.
 *
 * Handles hosting environments where WP_CONTENT_DIR lives outside ABSPATH
 * (e.g., Pressable stores core at /wordpress/core/X.Y.Z/ and content at /srv/htdocs/wp-content/).
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

        // Backup plugin directories.
        'wp-content/updraft',
        'wp-content/ai1wm-backups',
        'wp-content/backups-dup-lite',
        'wp-content/backups-dup-pro',

        // Production drop-in files (host-specific, break dev environments).
        'wp-content/object-cache.php',
        'wp-content/advanced-cache.php',
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
        $wp_root     = $this->get_wp_root();
        $content_dir = $this->get_content_dir();
        $exclusions  = $this->get_exclusions();

        if ( ! is_dir( $wp_root ) ) {
            throw new \Exception( "WordPress root directory not found: {$wp_root}" );
        }

        // Check if WP_CONTENT_DIR lives outside ABSPATH (e.g., Pressable).
        $content_outside = ! str_starts_with(
            rtrim( $content_dir, '/' ) . '/',
            rtrim( $wp_root, '/' ) . '/'
        );

        if ( $content_outside ) {
            // Two separate directory trees — must use PHP streaming (tar can't easily merge two roots).
            BM_Backup_Log_Stream::add( 'wp-content is outside ABSPATH — archiving both locations' );
            BM_Backup_Log_Stream::add( 'Core: ' . $wp_root );
            BM_Backup_Log_Stream::add( 'Content: ' . $content_dir );
            $this->archive_split_content( $output_path, $wp_root, $content_dir, $exclusions );
        } elseif ( $this->can_use_shell_tar() ) {
            BM_Backup_Log_Stream::add( 'Using shell tar for file archive' );
            $this->archive_with_tar( $output_path, $wp_root, $exclusions );
        } else {
            BM_Backup_Log_Stream::add( 'Using PHP streaming gzip for file archive' );
            $this->archive_with_streaming_gzip( $output_path, $wp_root, $exclusions );
        }

        $size = filesize( $output_path );
        if ( $size === false || $size === 0 ) {
            throw new \Exception( 'Archive creation failed — output file is empty.' );
        }

        return $size;
    }

    /**
     * Archive when WP_CONTENT_DIR is outside ABSPATH.
     *
     * Pass 1: WordPress core (ABSPATH) excluding its wp-content/ skeleton.
     * Pass 2: Real wp-content (WP_CONTENT_DIR) mapped to wp-content/ in the archive.
     */
    private function archive_split_content( string $output_path, string $wp_root, string $content_dir, array $exclusions ): void {
        if ( file_exists( $output_path ) ) {
            unlink( $output_path );
        }

        $gzip_level = $this->get_gzip_level();
        $gz = gzopen( $output_path, 'wb' . $gzip_level );
        if ( ! $gz ) {
            throw new \Exception( "Failed to open output file for writing: {$output_path}" );
        }

        $visited_dirs = [];

        try {
            // Pass 1: WordPress core, excluding the skeleton wp-content that ships with core.
            $core_exclusions = array_merge( $exclusions, [ 'wp-content' ] );
            BM_Backup_Log_Stream::add( 'Archiving WordPress core...' );
            $this->stream_directory( $gz, $wp_root, '', $core_exclusions, $visited_dirs );

            // Pass 2: Real wp-content directory, mapped to wp-content/ in the archive.
            BM_Backup_Log_Stream::add( 'Archiving wp-content from ' . $content_dir . '...' );
            $this->stream_directory( $gz, $content_dir, 'wp-content', $exclusions, $visited_dirs );

            // End-of-archive: two consecutive 512-byte zero blocks.
            gzwrite( $gz, str_repeat( "\0", 1024 ) );

        } finally {
            gzclose( $gz );
        }
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
     * Only used when wp-content is inside ABSPATH (standard layout).
     */
    private function archive_with_tar( string $output_path, string $wp_root, array $exclusions ): void {
        $exclude_args = '';
        foreach ( $exclusions as $pattern ) {
            $escaped = escapeshellarg( $pattern );
            $exclude_args .= " --exclude={$escaped}";
        }

        $output_escaped = escapeshellarg( $output_path );
        $root_escaped   = escapeshellarg( $wp_root );

        $command = "tar -czhf {$output_escaped}{$exclude_args} -C {$root_escaped} . 2>&1";

        exec( $command, $output, $return_code );

        if ( $return_code !== 0 ) {
            $error = implode( "\n", $output );
            throw new \Exception( "tar command failed (exit {$return_code}): {$error}" );
        }
    }

    /**
     * Create archive using streaming gzip (single-pass, no intermediate .tar on disk).
     * Used when wp-content is inside ABSPATH (standard layout) and shell tar is unavailable.
     */
    private function archive_with_streaming_gzip( string $output_path, string $wp_root, array $exclusions ): void {
        if ( file_exists( $output_path ) ) {
            unlink( $output_path );
        }

        $gzip_level = $this->get_gzip_level();
        $gz = gzopen( $output_path, 'wb' . $gzip_level );
        if ( ! $gz ) {
            throw new \Exception( "Failed to open output file for writing: {$output_path}" );
        }

        $visited_dirs = [];

        try {
            $this->stream_directory( $gz, $wp_root, '', $exclusions, $visited_dirs );

            // End-of-archive: two consecutive 512-byte zero blocks.
            gzwrite( $gz, str_repeat( "\0", 1024 ) );

        } finally {
            gzclose( $gz );
        }
    }

    /**
     * Stream a directory tree into an open gzip tar stream.
     *
     * @param resource $gz             Open gzip handle.
     * @param string   $source_dir     Absolute path to the directory to archive.
     * @param string   $archive_prefix Prefix for archive entry paths (e.g., '' or 'wp-content').
     * @param array    $exclusions     Exclusion patterns (matched against full archive path).
     * @param array    &$visited_dirs  Shared set of visited real paths (circular symlink protection).
     */
    private function stream_directory( $gz, string $source_dir, string $archive_prefix, array $exclusions, array &$visited_dirs ): void {
        $source_dir = rtrim( $source_dir, '/' );

        if ( ! is_dir( $source_dir ) ) {
            BM_Backup_Log_Stream::add( 'Directory not found, skipping: ' . $source_dir );
            return;
        }

        $dir_iterator = new RecursiveDirectoryIterator(
            $source_dir,
            RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
        );

        $filter = new RecursiveCallbackFilterIterator(
            $dir_iterator,
            function ( $file ) use ( $source_dir, $archive_prefix, $exclusions, &$visited_dirs ) {
                $pathname      = $file->getPathname();
                $relative_path = ltrim( str_replace( $source_dir, '', $pathname ), '/' );

                // Compute the full archive path (with prefix) for exclusion matching.
                $archive_path = $archive_prefix !== '' ? $archive_prefix . '/' . $relative_path : $relative_path;

                if ( $this->is_excluded( $archive_path, $exclusions ) ) {
                    return false;
                }

                // Circular symlink protection.
                if ( $file->isDir() ) {
                    $real = $file->getRealPath();
                    if ( $real !== false ) {
                        if ( isset( $visited_dirs[ $real ] ) ) {
                            BM_Backup_Log_Stream::add( 'Skipped circular symlink: ' . $archive_path );
                            return false;
                        }
                        $visited_dirs[ $real ] = true;
                    }
                }

                return true;
            }
        );

        $iterator = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::SELF_FIRST );

        foreach ( $iterator as $file ) {
            $pathname      = $file->getPathname();
            $relative_path = ltrim( str_replace( $source_dir, '', $pathname ), '/' );

            // Compute the full archive path (with prefix).
            $archive_path = $archive_prefix !== '' ? $archive_prefix . '/' . $relative_path : $relative_path;

            // Skip paths that exceed ustar's 255-char limit (155 prefix + 100 name).
            if ( strlen( $archive_path ) > 255 ) {
                BM_Backup_Log_Stream::add( 'Skipped (path too long): ' . substr( $archive_path, 0, 80 ) . '...' );
                continue;
            }

            if ( $file->isDir() ) {
                // Directory entry: trailing slash, zero size, typeflag '5'.
                gzwrite( $gz, $this->build_tar_header( $archive_path . '/', 0, $file->getMTime(), $file->getPerms(), '5' ) );

            } elseif ( $file->isFile() && $file->isReadable() ) {
                $size = $file->getSize();

                // Skip files > 8 GB (ustar size field is 11 octal digits = ~8 GB max).
                if ( $size > 8589934591 ) {
                    BM_Backup_Log_Stream::add( 'Skipped (> 8 GB): ' . $archive_path );
                    continue;
                }

                gzwrite( $gz, $this->build_tar_header( $archive_path, $size, $file->getMTime(), $file->getPerms(), '0' ) );

                // Stream file contents in 64 KB chunks directly into gzip.
                $fh = fopen( $file->getRealPath() ?: $file->getPathname(), 'rb' );
                if ( $fh ) {
                    while ( ! feof( $fh ) ) {
                        $chunk = fread( $fh, 65536 );
                        if ( $chunk !== false ) {
                            gzwrite( $gz, $chunk );
                        }
                    }
                    fclose( $fh );
                }

                // Pad data region to the next 512-byte boundary.
                $padding = ( 512 - ( $size % 512 ) ) % 512;
                if ( $padding > 0 ) {
                    gzwrite( $gz, str_repeat( "\0", $padding ) );
                }
            }
        }
    }

    /**
     * Get the configured gzip compression level.
     */
    private function get_gzip_level(): int {
        return max( 1, min( 9, (int) apply_filters( 'bm_backup_files_gzip_level', 3 ) ) );
    }

    /**
     * Build a 512-byte ustar tar header block.
     *
     * For paths longer than 100 characters, splits at a directory boundary into
     * the ustar prefix field (max 155 chars) and name field (max 100 chars),
     * supporting paths up to 255 characters total.
     */
    private function build_tar_header( string $path, int $size, int $mtime, int $mode, string $typeflag ): string {
        $name   = $path;
        $prefix = '';

        if ( strlen( $name ) > 100 ) {
            $slash = strrpos( substr( $name, 0, 155 ), '/' );
            if ( $slash !== false && strlen( $name ) - $slash - 1 <= 100 ) {
                $prefix = substr( $name, 0, $slash );
                $name   = substr( $name, $slash + 1 );
            }
        }

        // Assemble the 512-byte header with a placeholder checksum (8 spaces).
        $header = pack( 'a100', $name )                              // name       (100)
                . pack( 'a8',   sprintf( '%07o', $mode & 0777 ) . "\0" ) // mode    (8)
                . pack( 'a8',   "0000000\0" )                        // uid        (8)
                . pack( 'a8',   "0000000\0" )                        // gid        (8)
                . pack( 'a12',  sprintf( '%011o', $size ) . "\0" )   // size       (12)
                . pack( 'a12',  sprintf( '%011o', $mtime ) . "\0" )  // mtime      (12)
                . '        '                                          // checksum   (8) — placeholder
                . $typeflag                                           // typeflag   (1)
                . str_repeat( "\0", 100 )                            // linkname   (100)
                . "ustar\000"                                         // magic      (6)
                . "00"                                                // version    (2)
                . str_repeat( "\0", 32 )                             // uname      (32)
                . str_repeat( "\0", 32 )                             // gname      (32)
                . str_repeat( "\0", 8 )                              // devmajor   (8)
                . str_repeat( "\0", 8 )                              // devminor   (8)
                . pack( 'a155', $prefix )                            // prefix     (155)
                . str_repeat( "\0", 12 );                            // padding    (12)
        // = 512 bytes total

        // Compute checksum: sum of all byte values (placeholder spaces count as 0x20 each).
        $checksum = array_sum( array_map( 'ord', str_split( $header ) ) );

        // Write checksum as 6 octal digits + null + space (standard ustar format).
        return substr_replace( $header, sprintf( '%06o', $checksum ) . "\0 ", 148, 8 );
    }

    /**
     * Check if a relative path matches any exclusion pattern.
     *
     * Patterns are matched as a path prefix (e.g. "wp-content/uploads") OR as
     * a path segment anywhere in the path (e.g. "node_modules" matches
     * "wp-content/themes/builtmighty/node_modules/...").
     */
    private function is_excluded( string $relative_path, array $exclusions ): bool {
        foreach ( $exclusions as $pattern ) {
            // Direct prefix match (e.g. "wp-content/uploads/foo.jpg").
            if ( str_starts_with( $relative_path, $pattern ) ) {
                return true;
            }
            // Nested segment match (e.g. "node_modules" inside any subdirectory).
            if (
                str_contains( $relative_path, '/' . $pattern . '/' ) ||
                str_ends_with( $relative_path, '/' . $pattern )
            ) {
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
     * Get the WordPress root directory (ABSPATH without trailing slash).
     */
    private function get_wp_root(): string {
        return untrailingslashit( ABSPATH );
    }

    /**
     * Get the wp-content directory path.
     */
    private function get_content_dir(): string {
        return untrailingslashit( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' );
    }
}
