<?php
/**
 * Runtime environment detection for the Codespace bootstrap payload.
 *
 * The bootstrap pipeline needs to know which PHP version, database engine and
 * timezone to bake into the Codespace before it restores a backup. Everything
 * here is read-only introspection of the live server — nothing is persisted.
 *
 * Every method is safe to call outside a fully-booted WordPress (the unit tests
 * exercise the parsers directly) and always returns a usable value rather than
 * throwing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mighty_Backup_Environment {

    /**
     * PHP versions the Codespace images are built for. An unsupported value
     * still gets reported — the bootstrap warns and falls back on its side —
     * but this is what the admin UI advertises as known-good.
     */
    public const SUPPORTED_PHP = [ '8.1', '8.2', '8.3', '8.4' ];

    /**
     * Reported when the database engine can't be identified.
     */
    public const DEFAULT_DB_ENGINE = 'mysql';

    /**
     * The running PHP version as `major.minor`.
     *
     * Built from the version constants rather than parsing PHP_VERSION, which
     * carries distro suffixes ("8.2.0-1+ubuntu20.04.1+deb.sury.org+1") that a
     * naive explode() would drag along.
     */
    public static function php_version(): string {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * Detect the database engine and version from the live connection.
     *
     * @return array{engine:string, version:string} Engine is 'mysql' or 'mariadb'.
     */
    public static function db_server(): array {
        global $wpdb;

        $raw = '';

        try {
            if ( isset( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ) {
                $raw = (string) $wpdb->db_server_info();
            }

            // db_server_info() returns '' on some drivers; db_version() is the
            // normalized fallback, but it strips the MariaDB marker so engine
            // detection below has to run against the raw string first.
            if ( $raw === '' && isset( $wpdb ) && method_exists( $wpdb, 'db_version' ) ) {
                $raw = (string) $wpdb->db_version();
            }
        } catch ( \Throwable $e ) {
            $raw = '';
        }

        return self::parse_db_server_info( $raw );
    }

    /**
     * Parse a raw server-info string into engine + version.
     *
     * Split out from db_server() so it can be tested against real-world strings
     * without a database handle.
     *
     * Examples:
     *   "8.0.35"                                   => mysql   8.0.35
     *   "5.7.44-log"                               => mysql   5.7.44
     *   "10.6.16-MariaDB-1:10.6.16+maria~ubu2004"  => mariadb 10.6.16
     *   "5.5.5-10.6.16-MariaDB-1:10.6.16+maria"    => mariadb 10.6.16
     *
     * @param string $raw Raw value from db_server_info() / db_version().
     * @return array{engine:string, version:string}
     */
    public static function parse_db_server_info( string $raw ): array {
        $raw    = trim( $raw );
        $engine = stripos( $raw, 'mariadb' ) !== false ? 'mariadb' : self::DEFAULT_DB_ENGINE;

        // MariaDB 10.x and later prepend a fake "5.5.5-" so ancient MySQL
        // clients don't choke on the major version. Without stripping it, every
        // MariaDB 10/11 server would be reported as 5.5.5.
        $version_source = preg_replace( '/^5\.5\.5-/', '', $raw );

        $version = '';
        if ( preg_match( '/(\d+\.\d+\.\d+)/', (string) $version_source, $matches ) ) {
            $version = $matches[1];
        } elseif ( preg_match( '/(\d+\.\d+)/', (string) $version_source, $matches ) ) {
            $version = $matches[1];
        }

        return [
            'engine'  => $engine,
            'version' => $version,
        ];
    }

    /**
     * The site's timezone as an IANA zone name.
     *
     * WordPress stores either an IANA name in `timezone_string` OR a bare UTC
     * offset in `gmt_offset` (the "UTC+5:30" choices in Settings → General leave
     * timezone_string empty). In the offset case wp_timezone()->getName() hands
     * back "+05:30", which is not a zone name the bootstrap can feed to
     * date.timezone — so map it to a real zone, and fall back to UTC.
     */
    public static function timezone(): string {
        $stored = (string) get_option( 'timezone_string', '' );
        if ( $stored !== '' && self::is_valid_timezone( $stored ) ) {
            return $stored;
        }

        $offset = (float) get_option( 'gmt_offset', 0 );
        if ( $offset !== 0.0 ) {
            $guess = timezone_name_from_abbr( '', (int) round( $offset * HOUR_IN_SECONDS ), 0 );
            if ( is_string( $guess ) && $guess !== '' && self::is_valid_timezone( $guess ) ) {
                return $guess;
            }
        }

        return 'UTC';
    }

    /**
     * Whether a string is a known IANA timezone identifier.
     */
    public static function is_valid_timezone( string $timezone ): bool {
        return in_array( $timezone, timezone_identifiers_list(), true );
    }

    /**
     * Whether a string is a `major.minor` PHP version.
     *
     * The bootstrap reduces whatever it receives to major.minor, so anything
     * else (a patch version, a distro suffix) is a configuration mistake.
     */
    public static function is_valid_php_version( string $version ): bool {
        return (bool) preg_match( '/^\d+\.\d+$/', $version );
    }

    /**
     * Whether a string names a database engine the Codespace images can start.
     */
    public static function is_valid_db_engine( string $engine ): bool {
        return in_array( $engine, [ 'mysql', 'mariadb' ], true );
    }

    /**
     * Normalize a DigitalOcean Spaces endpoint to a bare host.
     *
     * The Spaces client builds its endpoint as 'https://' . $value, so a pasted
     * "https://nyc3.digitaloceanspaces.com/" produces "https://https://nyc3…/"
     * and every S3 call fails. The bootstrap also expands the value into
     * `host_bucket = %(bucket)s.<endpoint>`, which only works on a bare host.
     *
     * @param string $endpoint Raw user input.
     * @return string Host only — no scheme, port, path, or trailing slash.
     */
    public static function normalize_endpoint( string $endpoint ): string {
        $endpoint = trim( $endpoint );
        if ( $endpoint === '' ) {
            return '';
        }

        // Strip any scheme, then re-add one so wp_parse_url() reliably treats
        // the leading segment as a host rather than a path.
        $bare = preg_replace( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', '', $endpoint );
        $host = wp_parse_url( 'https://' . ltrim( (string) $bare, '/' ), PHP_URL_HOST );

        if ( ! is_string( $host ) || $host === '' ) {
            // Unparseable — hand back the input minus scheme/path so the user
            // sees what they typed rather than an empty field.
            return trim( explode( '/', (string) $bare )[0] );
        }

        return $host;
    }
}
