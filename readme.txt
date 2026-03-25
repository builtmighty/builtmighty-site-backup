=== Mighty Backup ===
Contributors: tylerjohnsondesign
Donate link: https://builtmighty.com
Tags: digital ocean, spaces, backups
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 2.2.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.

== Description ==

Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.

== Frequently Asked Questions ==

== Screenshots ==

== Changelog ==

= 2.2.0 =
* Renamed plugin label to "MightyBackup" across admin menu and settings page
* Promoted admin menu item from Settings submenu to a top-level menu item with a cloud icon

= 2.1.0 =
* Added public REST API health-check endpoint (GET /wp-json/mighty-backup/v1/check)
* Added "Check API Health" button on the Codespace settings tab for one-click API reachability testing

= 2.0.0 =
* Renamed plugin from "BuiltMighty Site Backup" to "Mighty Backup"
* Renamed all classes, functions, constants, hooks, filters, and slugs to use the new `Mighty_Backup` / `mighty_backup` naming convention
* Updated WP-CLI command from `bm-backup` to `mighty-backup`
* Updated REST API namespace from `bm-backup/v1` to `mighty-backup/v1`
* Existing database options and table names are preserved — no data migration needed
* Encryption constant `MIGHTY_BACKUP_SECRET` replaces `BM_BACKUP_SECRET` (old constant still accepted for backwards compatibility)

= 1.16.0 =
* Excluded backup plugin directories from file backups (UpdraftPlus, All-in-One WP Migration, Duplicator)
* Excluded production drop-in files from file backups (object-cache.php, advanced-cache.php)

= 1.15.0 =
* Fixed Pressable backups missing plugins and themes — ABSPATH on Pressable points to shared WordPress core (/wordpress/core/X.Y.Z/) while wp-content lives at /srv/htdocs/wp-content; archiver now detects when WP_CONTENT_DIR is outside ABSPATH and archives both locations
* Initialized GitHub update checker — plugin now auto-updates from GitHub releases
* Fixed backup log persisting after completion — log stream now cleared on dismiss
* Added diagnostic logging for archive root paths (helps debug hosting-specific issues)

= 1.14.0 =
* Security: Replaced MYSQL_PWD environment variable with --defaults-extra-file for mysqldump authentication (no longer visible in /proc on shared hosting)
* Fixed binary/BLOB column handling in PHP database export — binary data now exported as hex literals instead of string escaping
* Fixed symlink getRealPath() returning false on broken symlinks — now gracefully skipped with log message
* Added S3 minimum part size enforcement (5 MB floor) to prevent cryptic upload errors from filtered values
* Added empty client_path guard on download endpoint for defense-in-depth path traversal protection
* Improved retention manager — database and file prefix cleanup now independent; partial failures no longer block the other prefix
* Fixed misleading timezone comment in scheduler (uses WordPress timezone, not server timezone)

= 1.13.0 =
* Devcontainer version check now treats missing version field as outdated instead of erroring
* Fixed cross-repo blob SHA issue in devcontainer install/update — blobs are now copied to the target repo before tree creation

= 1.12.0 =
* Fixed stderr redirection in mysqldump — errors no longer silently corrupt SQL dumps
* Added tar safety checks — files > 8 GB or paths > 255 chars are skipped with log warning
* Added --timeout flag to WP-CLI run command (default 6 hours)
* Extracted shared mighty_backup_is_authorized_user() to eliminate code duplication
* Fixed symlink following in file archiver — prevents infinite loops from circular symlinks
* Fixed gzip concatenation in streamlined hybrid export — single gzip stream for compatibility
* Freed large temporary arrays in postmeta streamlined export

= 1.11.0 =
* Full plugin audit — performance hardening and security fixes
* Buffered log stream writes (10x fewer DB queries during backup)
* Lowered default gzip compression level from 6 to 3 for faster exports
* Batched table existence checks (single SHOW TABLES query instead of 8)
* Increased default upload part size from 10 MB to 25 MB
* Added static cache for settings reads across instances
* Added is_authorized_user() check to devcontainer AJAX handlers
* Replaced deprecated openssl_random_pseudo_bytes() with random_bytes()
* Clear live log on backup cancel
* Wrapped scheduler blog switching in try/finally for safety
* Added input validation for schedule_day and schedule_time
* Removed dead code ($old_chunks variable)

= 1.10.0 =
* Added live log stream — real-time backup progress display in admin UI
* Dark terminal-style log box with timestamped entries and auto-scroll
* Incremental log fetching via AJAX polling (since-index tracking)
* Log messages added throughout backup pipeline (DB export, file archive, upload, cleanup)
* Per-table progress logging for PHP database export paths
* Collapsible log box with expand/collapse toggle

= 1.9.0 =
* Added Streamlined Mode for lighter database exports
* Streamlined mode filters WooCommerce orders to last 90 days
* Log tables exported as structure only (no data) in streamlined mode
* Hybrid mysqldump + PHP export path for streamlined mode
* Fixed multisite Network Admin settings page not appearing
* Fixed secret key not saving on multisite (double-sanitization bug)
* Fixed AJAX URL for multisite network admin context

= 1.8.0 =
* Security hardening pass
* Added Codespace bootstrap key system (one-secret setup)
* Added REST API endpoint for Codespace configuration
* Added Devcontainer version check and PR creation
* Added Dev Mode detection (prevents dev sites from overwriting production backups)
* Added WP-CLI commands (run, status, cancel, list, prune, test, dev-mode)
* Added disk space pre-check before backup
* Added email notifications on backup failure

= 1.5.0 =
* Renamed "Client Path" field to "GitHub Repository" — accepts full GitHub URLs, extracts repo slug automatically
* API endpoint now returns `repository` key instead of `client_path`

= 1.3.0 =
* Added cancel-in-progress backup button in admin UI
* Added developer filters for batch size, gzip level, upload part size, concurrency, and retries
* Added action hooks before/after each backup pipeline step and on completion/failure
* Restricted settings page access to authorized email domains (builtmighty.com)
* Credential fields are now write-only — values never rendered to page source
* Optional MIGHTY_BACKUP_SECRET constant in wp-config.php adds extra encryption pepper
* Added PHPUnit test suite (30 tests covering settings, backup manager, retention, and logger)
* Improved admin accessibility (aria-live, role=progressbar, aria-valuenow)

= 1.2.0 =
* Added README.md with full documentation
* Added manual workflow_dispatch trigger to release ZIP workflow

= 1.1.0 =
* Added GitHub plugin updates workflow

= 1.0.0 =
* Initial launch
