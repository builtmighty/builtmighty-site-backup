=== Built Mighty Site Backup ===
Contributors: tylerjohnsondesign
Donate link: https://builtmighty.com
Tags: digital ocean, spaces, backups, builtmighty
Requires at least: 6.0
Tested up to: 10
Stable tag: 1.9.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.

== Description ==

Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.

== Frequently Asked Questions ==

== Screenshots ==

== Changelog ==

= 1.5.0 =
* Renamed "Client Path" field to "GitHub Repository" — accepts full GitHub URLs, extracts repo slug automatically
* API endpoint now returns `repository` key instead of `client_path`
* Updated version to 1.5.0

= 1.3.0 =
* Added cancel-in-progress backup button in admin UI
* Added developer filters for batch size, gzip level, upload part size, concurrency, and retries
* Added action hooks before/after each backup pipeline step and on completion/failure
* Restricted settings page access to authorized email domains (builtmighty.com)
* Credential fields are now write-only — values never rendered to page source
* Optional BM_BACKUP_SECRET constant in wp-config.php adds extra encryption pepper
* Added PHPUnit test suite (30 tests covering settings, backup manager, retention, and logger)
* Improved admin accessibility (aria-live, role=progressbar, aria-valuenow)

= 1.2.0 =
* Added README.md with full documentation
* Added manual workflow_dispatch trigger to release ZIP workflow
* Updated version number and readme.txt changelog

= 1.1.0 =
* Added GitHub plugin updates workflow

= 1.0.0 =
* Initial launch
