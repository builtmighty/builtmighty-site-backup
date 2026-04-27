<?php
/**
 * Placeholder-escape repair toolkit.
 *
 * `wpdb::placeholder_escape()` replaces every literal `%` in user data with a
 * 64-hex-char session token like `{fb86…c78d7}` so that wpdb::prepare()'s
 * format-string interpolation can't be hijacked. `remove_placeholder_escape()`
 * undoes that swap when values are passed through prepare(). The bug we
 * repair: if a `{HASH}` token ever gets stored back into the DB without going
 * through remove_placeholder_escape() first (e.g., via an export → import
 * round-trip of get_results() output), the token is permanent. A second
 * WordPress request — with a different session token — has no way to
 * recognize the stored token and can't unescape it.
 *
 * Detection and repair are intentionally regex-based (`\{[a-f0-9]{64}\}`)
 * rather than session-hash-specific: persisted tokens may have been minted
 * by an earlier WordPress session whose hash differs from the current one,
 * and we should still scrub them. The 64-hex-in-braces pattern has a
 * negligible false-positive rate against real user content.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Placeholder_Repair {

    /**
     * Regex matching a placeholder-escape token: literal `{` + 64 hex chars + `}`.
     * Total byte length is always 66 (1 + 64 + 1).
     */
    public const TOKEN_PATTERN = '/\{[a-f0-9]{64}\}/';

    /**
     * Bytes saved per replacement: 66-byte token swapped for a 1-byte `%`.
     */
    private const BYTES_SAVED = 65;

    /**
     * Detect whether the database has any persisted placeholder tokens.
     *
     * Returns a sample token (the first one we see) for reporting, or null
     * if the DB is clean. The sample is not used as a search key by other
     * methods — they all scan via the regex pattern, so multiple distinct
     * tokens minted across sessions are handled uniformly.
     *
     * @return string|null The first 66-byte `{HASH}` token found, or null.
     */
    public static function detect_sample_token(): ?string {
        global $wpdb;

        // PHP source `\\\\` produces bytes `\\` in the PHP string, which
        // arrive at MySQL as `\\` (passed through verbatim by wpdb), and
        // MySQL parses `\\` → `\` in string-literal context, giving the
        // REGEXP pattern `\{[a-f0-9]{64}\}` (literal braces around 64-hex).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $value = $wpdb->get_var(
            "SELECT option_value FROM `{$wpdb->options}` "
            . "WHERE option_value REGEXP '\\\\{[a-f0-9]{64}\\\\}' "
            . "LIMIT 1"
        );

        if ( is_string( $value ) && preg_match( self::TOKEN_PATTERN, $value, $m ) ) {
            return $m[0];
        }

        return null;
    }

    /**
     * Replace every `{HASH}` token in $payload with `%`, repairing the
     * `s:N:"…"` length prefixes of any PHP-serialized strings that contained
     * tokens.
     *
     * Each token is 66 bytes; `%` is 1 byte, so each replacement decreases
     * the byte length of the enclosing serialized string by exactly 65.
     * Tokens contain only `{`, hex digits, and `}` — none of which require
     * SQL or PHP-string escaping — so the byte arithmetic is exact and we
     * don't need to unescape/re-escape anything in the SQL representation.
     *
     * @param string $payload Raw bytes (may include SQL backslash escapes).
     * @return string Repaired payload.
     */
    public static function sanitize_string( string $payload ): string {
        if ( strpos( $payload, '{' ) === false ) {
            return $payload;
        }

        // Quick reject: regex test before doing the heavier callback work.
        if ( ! preg_match( self::TOKEN_PATTERN, $payload ) ) {
            return $payload;
        }

        // Match `s:N:"..."` where the quotes may be SQL-escaped (`\"`) or
        // bare (`"`). The body is non-greedy and tolerates escape sequences
        // (`\.`) so the closing quote isn't matched prematurely. Captures:
        //   1: declared length (digits)
        //   2: opening quote sequence (either `"` or `\"`)
        //   3: payload bytes between quotes
        // The closing quote is matched by backreferencing capture 2.
        $serialized_pattern = '/s:(\d+):(\\\\?")((?:\\\\.|[^"\\\\])*?)\2/s';

        $repaired = preg_replace_callback(
            $serialized_pattern,
            static function ( array $m ) {
                $declared_len = (int) $m[1];
                $quote        = $m[2];
                $body         = $m[3];

                $count = preg_match_all( self::TOKEN_PATTERN, $body );
                if ( $count === 0 || $count === false ) {
                    return $m[0];
                }

                $new_body = preg_replace( self::TOKEN_PATTERN, '%', $body );
                $new_len  = $declared_len - self::BYTES_SAVED * $count;

                return "s:{$new_len}:{$quote}{$new_body}{$quote}";
            },
            $payload
        );

        if ( ! is_string( $repaired ) ) {
            // preg_replace_callback returned null on backtrack-limit / catastrophic
            // input. Fall through to a plain regex replace rather than corrupting.
            $repaired = $payload;
        }

        // Sweep up any remaining bare tokens (outside `s:N:"…"` contexts).
        // Safe: '%' is a literal in SQL VALUES and in plain string columns.
        $swept = preg_replace( self::TOKEN_PATTERN, '%', $repaired );
        return is_string( $swept ) ? $swept : $repaired;
    }

    /**
     * Stream-process an SQL dump, applying sanitize_string() to every line
     * that may contain a token. Lines with no `{` byte are passed through
     * unchanged with no allocation overhead.
     *
     * @param resource $in_handle  Open read handle (rb).
     * @param resource $out_handle Open write handle (wb).
     * @return int Number of lines repaired.
     */
    public static function sanitize_sql_stream( $in_handle, $out_handle ): int {
        $repaired_lines = 0;

        // Read line-by-line. mysqldump's default extended-insert keeps each
        // INSERT row on a single line; serialized payloads cannot contain
        // raw newlines (literal '\n' in serialized data is encoded as the
        // two-byte sequence `\n` in SQL), so per-line scanning is safe.
        while ( ( $line = fgets( $in_handle ) ) !== false ) {
            // Cheap fast path: only run the regex if we see a `{` byte at all.
            if ( strpos( $line, '{' ) !== false ) {
                $sanitized = self::sanitize_string( $line );
                if ( $sanitized !== $line ) {
                    $line = $sanitized;
                    ++$repaired_lines;
                }
            }
            fwrite( $out_handle, $line );
        }

        return $repaired_lines;
    }

    /**
     * Convenience wrapper: open file paths, run sanitize_sql_stream, close.
     *
     * @return int Number of repaired lines.
     */
    public static function sanitize_sql_file( string $in_path, string $out_path ): int {
        $in = fopen( $in_path, 'rb' );
        if ( ! $in ) {
            throw new \RuntimeException( "Failed to open sanitize input: {$in_path}" );
        }
        $out = fopen( $out_path, 'wb' );
        if ( ! $out ) {
            fclose( $in );
            throw new \RuntimeException( "Failed to open sanitize output: {$out_path}" );
        }

        try {
            return self::sanitize_sql_stream( $in, $out );
        } finally {
            fclose( $in );
            fclose( $out );
        }
    }

    /**
     * Build the list of `[table, pk, payload_column]` tuples to scan/repair.
     *
     * Includes the core options/posts/*meta tables plus any custom prefixed
     * `*_options` table on multisite (one per blog). Filterable via
     * `mighty_backup_placeholder_scan_targets`.
     *
     * @return array<int, array{table:string,pk:string,column:string}>
     */
    public static function scan_targets(): array {
        global $wpdb;

        $targets = [
            [ 'table' => $wpdb->options,     'pk' => 'option_id', 'column' => 'option_value' ],
            [ 'table' => $wpdb->posts,       'pk' => 'ID',        'column' => 'post_content' ],
            [ 'table' => $wpdb->posts,       'pk' => 'ID',        'column' => 'post_excerpt' ],
            [ 'table' => $wpdb->posts,       'pk' => 'ID',        'column' => 'post_title' ],
            [ 'table' => $wpdb->postmeta,    'pk' => 'meta_id',   'column' => 'meta_value' ],
            [ 'table' => $wpdb->termmeta,    'pk' => 'meta_id',   'column' => 'meta_value' ],
            [ 'table' => $wpdb->usermeta,    'pk' => 'umeta_id',  'column' => 'meta_value' ],
            [ 'table' => $wpdb->commentmeta, 'pk' => 'meta_id',   'column' => 'meta_value' ],
        ];

        if ( is_multisite() ) {
            $like  = $wpdb->esc_like( $wpdb->base_prefix ) . '%\\_options';
            $extra = $wpdb->get_col(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $like )
            );
            foreach ( (array) $extra as $tbl ) {
                if ( $tbl === $wpdb->options ) {
                    continue;
                }
                $targets[] = [ 'table' => $tbl, 'pk' => 'option_id', 'column' => 'option_value' ];
            }
        }

        return apply_filters( 'mighty_backup_placeholder_scan_targets', $targets );
    }

    /**
     * Count rows containing a placeholder token, per scan target.
     *
     * @return array<int, array{table:string,column:string,count:int}>
     */
    public static function count_corruption(): array {
        global $wpdb;

        $results = [];
        foreach ( self::scan_targets() as $target ) {
            // REGEXP is run server-side against on-disk bytes, so this is
            // not affected by the current session's placeholder swap.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $count = (int) $wpdb->get_var( sprintf(
                "SELECT COUNT(*) FROM `%s` WHERE `%s` REGEXP '\\\\{[a-f0-9]{64}\\\\}'",
                $target['table'],
                $target['column']
            ) );
            $results[] = [
                'table'  => $target['table'],
                'column' => $target['column'],
                'count'  => $count,
            ];
        }

        return $results;
    }

    /**
     * Repair every corrupted row in every scan target.
     *
     * Uses raw $wpdb->query with prepare() — NOT update_option() or
     * update_post_meta(), which would re-escape '%' and recreate the bug.
     *
     * @return array{rows_updated:int,errors:string[]}
     */
    public static function repair_all(): array {
        global $wpdb;

        $rows_updated = 0;
        $errors       = [];

        foreach ( self::scan_targets() as $target ) {
            $table  = $target['table'];
            $pk     = $target['pk'];
            $column = $target['column'];

            $last_pk = 0;
            $batch   = 200;

            while ( true ) {
                // Fetch a batch of corrupted rows. The REGEXP runs against
                // on-disk bytes server-side; the column value returned via
                // wpdb still passes through placeholder_escape, so we strip
                // that with remove_placeholder_escape below. wpdb::prepare()
                // does not transform backslashes in the query template, so
                // the same 4-backslashes-per-side encoding works as in
                // detect_sample_token().
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $rows = $wpdb->get_results( $wpdb->prepare(
                    sprintf(
                        "SELECT `%s` AS pk, `%s` AS payload FROM `%s` "
                        . "WHERE `%s` > %%d AND `%s` REGEXP '\\\\{[a-f0-9]{64}\\\\}' "
                        . "ORDER BY `%s` ASC LIMIT %%d",
                        $pk, $column, $table, $pk, $column, $pk
                    ),
                    $last_pk,
                    $batch
                ), ARRAY_A );

                if ( empty( $rows ) ) {
                    break;
                }

                foreach ( $rows as $row ) {
                    $last_pk = (int) $row['pk'];

                    // Strip the *current session's* hash so we see real `%`s
                    // for any actual user data. Persisted hashes from prior
                    // sessions are unaffected and will be caught by the
                    // regex-based sanitize_string below.
                    $original = $wpdb->remove_placeholder_escape( (string) $row['payload'] );
                    $repaired = self::sanitize_string( $original );

                    if ( $repaired === $original ) {
                        continue;
                    }

                    $updated = $wpdb->query( $wpdb->prepare(
                        sprintf(
                            "UPDATE `%s` SET `%s` = %%s WHERE `%s` = %%d",
                            $table, $column, $pk
                        ),
                        $repaired,
                        $last_pk
                    ) );

                    if ( $updated === false ) {
                        $errors[] = sprintf(
                            '%s.%s pk=%d: %s',
                            $table, $column, $last_pk, $wpdb->last_error
                        );
                    } else {
                        $rows_updated += (int) $updated;
                    }
                }
            }
        }

        return [ 'rows_updated' => $rows_updated, 'errors' => $errors ];
    }

    /**
     * Best-effort cache flush after a repair: object cache + popular page
     * caches. Each step is independently guarded — a missing plugin is not
     * an error.
     */
    public static function flush_caches(): void {
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }

        // LiteSpeed Cache.
        if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
            \LiteSpeed_Cache_API::purge_all();
        } elseif ( has_action( 'litespeed_purge_all' ) ) {
            do_action( 'litespeed_purge_all' );
        }

        // WP Rocket.
        if ( function_exists( 'rocket_clean_domain' ) ) {
            \rocket_clean_domain();
        }

        // W3 Total Cache.
        if ( function_exists( 'w3tc_flush_all' ) ) {
            \w3tc_flush_all();
        }

        // Site-specific extension hook.
        do_action( 'mighty_backup_after_repair_flush' );
    }
}
