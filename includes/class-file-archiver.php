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

class Mighty_Backup_File_Archiver {

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

        // Devcontainer config is owned by the GitHub repo (source of truth) and
        // deployed to the Codespace by cloning that repo. Backing it up would let
        // a stale copy from the source site overwrite the repo's version on import,
        // rolling back devcontainer upgrades. Keep it out of the archive entirely.
        '.devcontainer',

        // Backup plugin directories.
        'wp-content/updraft',
        'wp-content/ai1wm-backups',
        'wp-content/backups-dup-lite',
        'wp-content/backups-dup-pro',

        // Production drop-in files (host-specific, break dev environments).
        'wp-content/object-cache.php',
        'wp-content/advanced-cache.php',

        // Hosting-managed SQL snapshots (WP Engine, Pressable). Redundant —
        // Mighty Backup generates its own DB dump separately — and racy: the
        // host rotates the file, causing fopen() to fail intermittently even
        // when is_readable() returns true. Keep this excluded by default.
        'wp-content/mysql.sql',

        // NOTE: this plugin's own vendor/ directory is deliberately NOT excluded.
        //
        // 2.16.0 excluded it, back when the bundled tree was 5,193 files / 50 MB.
        // The same release pruned it to 720 files / 4.9 MB (~1.5 MB gzipped), which
        // inverted the trade-off: a site hydrated from one of these archives came up
        // with Mighty Backup installed but its dependencies stripped, reporting
        // "Missing dependencies: AWS SDK" with no way to fix it without a shell.
        // Shipping the tree costs almost nothing and means a restored site or
        // Codespace has a working plugin. Do not re-add that exclusion.
        //
        // If you ever do need to exclude a single plugin's dependencies, use the
        // "<plugin-slug>/vendor" form — never a bare "vendor", which hits the
        // segment branch in is_excluded() and would strip EVERY plugin's and
        // theme's vendor directory site-wide.
    ];

    private Mighty_Backup_Settings $settings;

    public function __construct( Mighty_Backup_Settings $settings ) {
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
            Mighty_Backup_Log_Stream::add( 'wp-content is outside ABSPATH — archiving both locations' );
            Mighty_Backup_Log_Stream::add( 'Core: ' . $wp_root );
            Mighty_Backup_Log_Stream::add( 'Content: ' . $content_dir );
            $this->archive_split_content( $output_path, $wp_root, $content_dir, $exclusions );
        } elseif ( $this->can_use_shell_tar() ) {
            Mighty_Backup_Log_Stream::add( 'Using shell tar for file archive' );
            $this->archive_with_tar( $output_path, $wp_root, $exclusions );
        } else {
            Mighty_Backup_Log_Stream::add( 'Using PHP streaming gzip for file archive' );
            $this->archive_with_streaming_gzip( $output_path, $wp_root, $exclusions );
        }

        $size = filesize( $output_path );
        if ( $size === false || $size === 0 ) {
            throw new \Exception( 'Archive creation failed — output file is empty.' );
        }

        // Final guard: walk the tar structure end-to-end. Catches header/data
        // desync bugs that would otherwise ship a "successful" but unusable
        // backup (see plans/scan-over-this-plugin-merry-hoare.md).
        $this->verify_archive_structure( $output_path );

        return $size;
    }

    /**
     * Walk the tar.gz structure end-to-end and throw if anything is off.
     *
     * Prefers shell `tar -tzf` when available (most accurate). Falls back to
     * a streaming gzip+tar header walker that follows the declared size of
     * each entry and confirms the stream ends with the two zero-block EOF
     * terminator.
     */
    private function verify_archive_structure( string $path ): void {
        Mighty_Backup_Log_Stream::add( 'Verifying archive structure...' );

        if ( $this->can_use_shell_tar() ) {
            $escaped = escapeshellarg( $path );
            $out     = [];
            $code    = 0;
            exec( "tar -tzf {$escaped} > /dev/null 2>&1", $out, $code );
            if ( $code !== 0 ) {
                throw new \RuntimeException( sprintf(
                    'Post-archive verification failed: tar -tzf returned exit %d. '
                    . 'The archive is structurally invalid — refusing to upload.',
                    $code
                ) );
            }
            Mighty_Backup_Log_Stream::add( 'Archive verified.' );
            return;
        }

        $this->verify_archive_structure_php( $path );
        Mighty_Backup_Log_Stream::add( 'Archive verified.' );
    }

    /**
     * PHP fallback: walk the gzip stream block-by-block. Confirms each tar
     * header has a parseable size field and the stream ends with two zero
     * blocks (the standard tar end-of-archive terminator).
     *
     * Skipping data regions uses `gzseek( ..., SEEK_CUR )` which is O(size)
     * because zlib has no real seek — but verification is one pass per
     * backup, so the cost is acceptable.
     */
    private function verify_archive_structure_php( string $path ): void {
        $gz = @gzopen( $path, 'rb' );
        if ( ! $gz ) {
            throw new \RuntimeException( 'Post-archive verification failed: cannot reopen archive for reading.' );
        }

        $zero_block = str_repeat( "\0", 512 );

        try {
            while ( true ) {
                $header = gzread( $gz, 512 );
                if ( $header === false || strlen( $header ) === 0 ) {
                    // Reached the end without finding the EOF terminator pair.
                    throw new \RuntimeException(
                        'Post-archive verification failed: stream ended without an EOF terminator (missing two trailing zero blocks).'
                    );
                }

                if ( strlen( $header ) < 512 ) {
                    throw new \RuntimeException( sprintf(
                        'Post-archive verification failed: short read at header boundary (got %d bytes, expected 512). Archive is truncated.',
                        strlen( $header )
                    ) );
                }

                if ( $header === $zero_block ) {
                    $next = gzread( $gz, 512 );
                    if ( $next === $zero_block ) {
                        return; // Valid EOF terminator.
                    }
                    throw new \RuntimeException(
                        'Post-archive verification failed: single zero block found where two were expected — archive is desynced.'
                    );
                }

                // Parse the size field (offset 124, 12 bytes, octal, null-terminated).
                $size_field = substr( $header, 124, 12 );
                $size_octal = trim( $size_field, "\0 " );
                if ( $size_octal === '' || ! preg_match( '/^[0-7]+$/', $size_octal ) ) {
                    throw new \RuntimeException( sprintf(
                        'Post-archive verification failed: header has invalid size field (raw: %s). Archive is desynced.',
                        bin2hex( $size_field )
                    ) );
                }
                $declared_size = octdec( $size_octal );

                // Skip over the declared data region (rounded up to 512 boundary).
                $skip = (int) ( ceil( $declared_size / 512 ) * 512 );
                if ( $skip > 0 ) {
                    // gzseek returns 0 on success.
                    if ( gzseek( $gz, $skip, SEEK_CUR ) !== 0 ) {
                        throw new \RuntimeException(
                            'Post-archive verification failed: cannot seek over declared data region (archive truncated or zlib error).'
                        );
                    }
                    // Confirm the seek didn't run off the end. zlib's gzseek
                    // returns 0 even when EOF was hit, so we re-check via
                    // gzeof + a single peek.
                    if ( gzeof( $gz ) ) {
                        throw new \RuntimeException(
                            'Post-archive verification failed: declared data region extends past end of stream. Archive is desynced (e.g. header announced N bytes but fewer were written).'
                        );
                    }
                }
            }
        } finally {
            gzclose( $gz );
        }
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
            Mighty_Backup_Log_Stream::add( 'Archiving WordPress core...' );
            $this->stream_directory( $gz, $wp_root, '', $core_exclusions, $visited_dirs );

            // Pass 2: Real wp-content directory, mapped to wp-content/ in the archive.
            Mighty_Backup_Log_Stream::add( 'Archiving wp-content from ' . $content_dir . '...' );
            $this->stream_directory( $gz, $content_dir, 'wp-content', $exclusions, $visited_dirs );

            // End-of-archive: two consecutive 512-byte zero blocks.
            $this->safe_gzwrite( $gz, str_repeat( "\0", 1024 ) );

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

        if ( $return_code >= 2 ) {
            $error = implode( "\n", $output );
            throw new \Exception( "tar command failed (exit {$return_code}): {$error}" );
        }

        // Exit 1 = "some files changed during read" — expected on live sites
        // where object caches, sessions, and logs are constantly written.
        if ( $return_code === 1 ) {
            Mighty_Backup_Log_Stream::add( 'tar: some files changed during archival (non-fatal, archive is valid)' );
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
            $this->safe_gzwrite( $gz, str_repeat( "\0", 1024 ) );

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
            Mighty_Backup_Log_Stream::add( 'Directory not found, skipping: ' . $source_dir );
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
                            Mighty_Backup_Log_Stream::add( 'Skipped circular symlink: ' . $archive_path );
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
                Mighty_Backup_Log_Stream::add( 'Skipped (path too long): ' . substr( $archive_path, 0, 80 ) . '...' );
                continue;
            }

            if ( $file->isDir() ) {
                // Directory entry: trailing slash, zero size, typeflag '5'.
                $this->safe_gzwrite( $gz, $this->build_tar_header( $archive_path . '/', 0, $file->getMTime(), $file->getPerms(), '5' ) );

            } elseif ( $file->isFile() && $file->isReadable() ) {
                $size = $file->getSize();

                // Skip files > 8 GB (ustar size field is 11 octal digits = ~8 GB max).
                if ( $size > 8589934591 ) {
                    Mighty_Backup_Log_Stream::add( 'Skipped (> 8 GB): ' . $archive_path );
                    continue;
                }

                $source = $file->getRealPath() ?: $file->getPathname();
                // Suppress the E_WARNING so a permission-denied surface as our
                // own clearer exception instead of an admin notice.
                $fh = @fopen( $source, 'rb' );

                if ( ! $fh ) {
                    // We have NOT written the header yet, so the archive is
                    // still internally consistent. Abort loud — silently
                    // emitting a header we cannot back with data would
                    // desync every later entry in the tar.
                    throw new \RuntimeException( sprintf(
                        'Could not open file for archival: %s (is_readable() returned true, fopen() failed). '
                        . 'Aborting to prevent archive desync.',
                        $archive_path
                    ) );
                }

                $this->safe_gzwrite( $gz, $this->build_tar_header( $archive_path, $size, $file->getMTime(), $file->getPerms(), '0' ) );

                $written = 0;
                try {
                    while ( ! feof( $fh ) ) {
                        $chunk = fread( $fh, 65536 );
                        if ( $chunk === false ) {
                            throw new \RuntimeException( sprintf(
                                'fread failed on %s after %d of %d bytes',
                                $archive_path,
                                $written,
                                $size
                            ) );
                        }
                        if ( $chunk === '' ) {
                            // Defensive: avoid infinite loops if feof somehow
                            // stays false while reads return empty.
                            break;
                        }
                        $this->safe_gzwrite( $gz, $chunk );
                        $written += strlen( $chunk );
                    }
                } finally {
                    fclose( $fh );
                }

                if ( $written !== $size ) {
                    throw new \RuntimeException( sprintf(
                        'Short read on %s — header declared %d bytes, wrote %d. '
                        . 'Aborting to prevent archive desync (file may have been '
                        . 'truncated mid-archive).',
                        $archive_path,
                        $size,
                        $written
                    ) );
                }

                // Pad data region to the next 512-byte boundary. Safe to use
                // declared size here since we verified actual bytes == declared.
                $padding = ( 512 - ( $size % 512 ) ) % 512;
                if ( $padding > 0 ) {
                    $this->safe_gzwrite( $gz, str_repeat( "\0", $padding ) );
                }
            }
        }
    }

    /**
     * Write to a gzip stream, throwing on short writes / failure.
     *
     * PHP's gzwrite() returns the number of UNCOMPRESSED bytes written, or
     * false on failure. Both partial writes and failures must abort the
     * archive — silent short writes desync the tar stream the same way a
     * missing data region does.
     */
    private function safe_gzwrite( $gz, string $data ): void {
        $len = strlen( $data );
        if ( $len === 0 ) {
            return;
        }
        $written = gzwrite( $gz, $data );
        if ( $written === false || $written !== $len ) {
            throw new \RuntimeException( sprintf(
                'gzwrite failed: wrote %s of %d bytes (disk full or zlib error)',
                $written === false ? 'false' : (string) $written,
                $len
            ) );
        }
    }

    /**
     * Get the configured gzip compression level.
     */
    private function get_gzip_level(): int {
        return max( 1, min( 9, (int) apply_filters( 'mighty_backup_files_gzip_level', 3 ) ) );
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
