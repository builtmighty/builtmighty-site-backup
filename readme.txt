=== Mighty Backup ===
Contributors: tylerjohnsondesign
Donate link: https://builtmighty.com
Tags: digital ocean, spaces, backups
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 3.0.1
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.

== Description ==

Automated site backups to DigitalOcean Spaces. Creates nightly and on-demand backups of the database and file system for use with the staged-loader Codespace pipeline.

== Frequently Asked Questions ==

== Screenshots ==

== Changelog ==

= 3.0.1 =
**Backups now include the plugin's own dependencies, so a restored site or Codespace comes up working instead of reporting "Missing dependencies: AWS SDK".**

* **The plugin's own `vendor/` directory is archived again.** 2.16.0 added `mighty-backup/vendor` to `Mighty_Backup_File_Archiver::DEFAULT_EXCLUSIONS` back when the bundled tree was 5,193 files / 50 MB. That same release pruned it to 720 files / 4.9 MB — about 1.5 MB gzipped — which inverted the trade-off, but the exclusion stayed. The result: every Codespace hydrated from a backup came up with Mighty Backup installed and its dependencies stripped, showing *"Missing dependencies: AWS SDK"* and pointing the operator at a `composer install` they often had no shell to run. The exclusion is removed; the guard in CI now fails if it is ever re-added. Note this only affects **new** backups — a Codespace built from an archive taken before 3.0.1 still needs a repair (see below). To be explicit about a detail that misleads: the notice named only the AWS SDK and not Action Scheduler because on a WooCommerce site WooCommerce supplies Action Scheduler, so it looked present even when the whole `vendor/` folder was gone.
* **New: repair dependencies without a shell.** A **Repair dependencies** button on the warning notice, and `wp mighty-backup repair deps` on the command line, download the release matching the *installed* version — falling back to the latest — and restore `vendor/` in place using WordPress's own filesystem API. It never upgrades the plugin. `wp mighty-backup repair deps --status` reports what is missing and why without changing anything. This covers the cases an archive fix cannot: a deploy pipeline filtering `**/vendor`, or a truncated extraction during an auto-update.
* **A site on PHP older than 8.1 no longer white-screens.** The bundled `vendor/composer/platform_check.php` throws an uncaught exception on PHP below 8.1, and because the autoloader is required at plugin-load time that took down the whole site — front end included — before WordPress could render any notice. WordPress's "Requires PHP" header blocks activation and updates, but not a plugin that is already active when a host downgrades PHP. The autoloader is now only required when the PHP version actually satisfies it, so the site stays up and the dependency notice explains that PHP is the problem.
* **The dependency notice now explains the actual cause.** It previously told everyone to run `composer install`, which is both wrong (the tree ships bundled) and impossible on a WP-admin-only host. It now distinguishes between PHP older than 8.1 (where nothing but a PHP upgrade helps, because the bundled `platform_check.php` refuses to load), a `vendor/` folder that was stripped, and missing PHP extensions — and gives a recovery path that works with WP admin alone. The same correction is applied to the backup-manager exception and the three settings AJAX errors.
* **Fixed: the release smoke test could never fail.** The "Smoke-test the pruned AWS SDK" step added in 2.16.0 reported failures with `exit("message")`. PHP's `exit()` with a string argument prints it and returns status **0**, so every assertion in that step — including the check that unused AWS services really were pruned — was decorative and could not block a bad release. It now writes to stderr and exits 1.
* **Fixed: `vendor/` could drift from `composer.lock` unnoticed.** Dependabot bumps the lock but cannot regenerate the committed tree, so a dependency PR could go green while shipping the *old* library — an advertised security fix that never actually reached a site. CI now compares every package and version in `composer.lock` against `vendor/composer/installed.json`.
* **Release pipeline hardening.** 2.17.0 published with no ZIP asset at all (its build run was cancelled), so `releases/latest/download/mighty-backup.zip` — the URL in the README and the WP-CLI install command — returned 404 for the two days it was the latest release. Auto-updates were unaffected, since those download the source zipball. The workflow now re-queries the release after upload and fails if the asset is missing or implausibly small, and the `workflow_dispatch` path no longer resolves the target release with `gh release list --limit 1`, which returns the most recently *created* release and could overwrite the wrong one.

= 3.0.0 =
**The devcontainer is now owned end-to-end by the template. `.devcontainer/` is replaced wholesale on every update, not merged into.**

Major version because this changes what an update PR does to a repo. Before, an update overwrote the files the new template shipped and left everything else in place, so a repo accumulated whatever it had ever been given. Now the directory is whatever the template says and nothing else — which is the point, but it means anything a repo added under `.devcontainer/` is removed on the next update. **Read the first bullet before upgrading a repo that has custom devcontainer files.**

* **`.devcontainer/` is replaced in full.** An update branch now removes every file under `.devcontainer/` and lays down the new template. **`.devcontainer/setup/` is no longer exempt** — earlier versions deliberately preserved it, so a repo relying on custom setup scripts will lose them. They are still in git history, and the PR body now lists every removed path by name so a reviewer can restore anything that mattered. Nothing outside `.devcontainer/` is touched.
* **Branch renamed to `update-devcontainer-v{version}`** (was `devcontainer-update-{version}`).
* **The branch is created last, after the commit exists.** It used to be created before the ~90 blob API calls that build the tree, so any failure in between — a rate limit, a timeout, a dropped connection — left an orphan branch behind, and every retry then died on "Branch already exists" until someone deleted it by hand. A collision is now detected up front with a single request (so an already-open PR fails in a second instead of after the whole copy), and a mid-run failure leaves nothing but unreferenced blobs, which GitHub garbage-collects.
* **`devcontainer.json` keeps its comments.** Setting `hostRequirements.cpus` ran the file through `json_decode` + `wp_json_encode`, which meant every updated repo received a comment-stripped, reformatted file — the template's is roughly half commentary explaining why each setting is what it is, and all of it was being deleted on every upgrade to change one integer. The value is now rewritten in place, leaving every other byte identical; the result is parsed back and verified before it is committed, falling back to the old re-encode only if that check fails. The same fix applies to the resize PR path.
* **Sizing is no longer silently reset for repos with no `version` field.** `check_version()` omitted `current_cpus` on that path, so an update would quietly re-derive the machine size from disk usage and discard a repo that had been tuned to a larger machine.
* **Truncated file listings abort the update.** GitHub caps very large recursive tree responses and flags them `truncated`; that flag was ignored, so an oversized repo could have produced a PR that copied a partial `.devcontainer` or missed files that should have been deleted — both of which look like success. It now fails with an explanation instead.
* **First tests for the devcontainer manager.** The class had no coverage at all, and the tree builder is the piece that can delete a repo's files without replacing them. The destructive logic and the cpus patcher are now pure static functions covered by 19 tests, including a byte-level assertion against the real template that only the `cpus` digits change.

= 2.17.0 =
**`/codespace-config` now returns the full documented field set — five new environment fields, plus an opt-in multisource object layout.**

* **Added the five missing payload fields.** The Codespace bootstrap contract documents thirteen fields; the endpoint was returning eight. `php_version`, `db_engine`, `db_version`, `timezone` and `multisource` are now included. Each is detected from the live server by a new `Mighty_Backup_Environment` class — `php_version` from `PHP_MAJOR_VERSION`/`PHP_MINOR_VERSION` (so distro suffixes like `8.2.0-1+ubuntu` never leak through), engine and version from `$wpdb->db_server_info()` (stripping MariaDB 10+'s fake `5.5.5-` client-compatibility prefix, which would otherwise report every MariaDB 10/11 server as 5.5.5), and `timezone` as a real IANA zone name. Sites configured with a bare UTC offset store no `timezone_string`, and `wp_timezone()->getName()` returns `"+05:30"` for them, which is not a value `date.timezone` accepts — those now resolve to a zone name, falling back to `UTC`. Each field has an optional override on the new **Codespace → Codespace Environment** section (and via `wp mighty-backup settings set`); blank means "report what the server actually is", which is what the field's placeholder shows.
* **`client_path` is emitted again.** 2.10.0 renamed the payload key from `client_path` to `repository`, but the bootstrap contract accepts no alias for `client_path`. Both keys are now present and carry the same value, so old and new bootstraps both work.
* **`do_spaces_endpoint` is normalized to a bare host.** The endpoint setting was free text, and `Mighty_Backup_Spaces_Client` builds its endpoint as `'https://' . $value` — so a pasted `https://nyc3.digitaloceanspaces.com` produced `https://https://nyc3.digitaloceanspaces.com` and broke every S3 call, not just the payload. Scheme, port, path and trailing slash are now stripped on save and on output. The bootstrap also expands this value into `host_bucket = %(bucket)s.<endpoint>`, which only works on a bare host.
* **`hosting_provider` defaults to `generic`.** It previously reported an empty string when unset. Values are also lowercased.
* **New `multisource` mode for sites that share a repository.** When enabled, backups are named after the site instead of the generic `backup` stem — `<repo>/databases/<site-name>-<timestamp>.sql.gz` instead of `<repo>/databases/backup-<timestamp>.sql.gz` — so several sites can share one Spaces path and still be told apart. The site name defaults to a slug of the site domain and can be set explicitly. The payload carries it as `source_name` (`"backup"` when multisource is off) since siblings share a repo slug and the bootstrap cannot derive the name from `client_path`.
* **Retention is now scoped to the site's own objects.** `Mighty_Backup_Retention_Manager` listed everything under `<client_path>/databases/` and sorted by full key. Under a shared prefix that sort orders by site name *before* timestamp, so a `retention_count` of 7 across ten sibling sites would have kept seven objects belonging to the alphabetically-last site and queued every other site's entire history for deletion — the 1-hour `LastModified` veto only spares uploads from the last hour. Listings are now prefixed with the object stem (`databases/<stem>-`), which S3 filters server-side. Two consequences: with the default stem the prefix is `databases/backup-`, which every previously-uploaded object already matches, so single-source behaviour is byte-for-byte unchanged; and enabling multisource leaves existing `backup-*` objects in the bucket permanently — they fall outside retention and are never deleted (a live-log line says so; prune them by hand if you no longer want them).
* **Cross-tenant hardening for shared prefixes.** The daily orphan-multipart sweep aborted every in-flight multipart upload under `<client_path>/` older than the cutoff, which under a shared prefix could kill a sibling site's live multi-GB upload; it now skips uploads outside this site's stem. The presigned-download guard checked only that a key started with `<client_path>/`, which under a shared prefix no longer isolates one site; it now also requires the object stem (still allowing `backup-` so pre-multisource history stays downloadable).
* **Tests.** New `EnvironmentTest` (server-info parsing across MySQL, Percona and MariaDB strings including the `5.5.5-` prefix; timezone resolution; endpoint normalization) and `ApiEndpointTest` (exact key set, `multisource` as a real JSON boolean with every other value a string, overrides beating detection, documented defaults). `RetentionManagerTest` gained the assertions it was missing — `test_prune_keeps_newest_backups` previously asserted only a *count* of deleted keys, never which ones, so the key-sort behaviour the pruner depends on was unguarded; its fixture dates were also unpadded (`backup-2026-01-1` … `-10`), making the fixture's own lexicographic order disagree with its chronological order. Also fixed a static-cache leak in `SettingsTest` where the first test's stubbed options bled into every later case.

= 2.16.0 =
**Pruned the bundled Composer dependencies — 5,193 files down to 720, and dev libraries no longer load on every request.**

* **Stopped shipping 406 unused AWS services.** The bundled AWS SDK carried API models and client classes for all 412 AWS services (43 MB of JSON in `vendor/aws/aws-sdk-php/src/data/` alone) when the plugin only ever talks to S3. `composer.json` now declares `extra: {"aws/aws-sdk-php": ["S3"]}` and runs the SDK's own supported pruner, `Aws\Script\Composer\Composer::removeUnusedServices`, on `pre-autoload-dump`. `vendor/` drops from 5,193 files / 50.2 MB to 720 files / 4.9 MB. The AWS surface the plugin actually uses is unchanged: `S3Client`, `MultipartUploader` (including resume-from-state), the `ListObjectsV2` and `ListMultipartUploads` paginators, SigV4 presigned download URLs, and the lifecycle-policy calls.
* **Dev dependencies no longer execute on production page loads.** PHPUnit, Mockery, Brain Monkey and friends were committed to `vendor/`, and because Composer records package bootstrap files in `vendor/composer/autoload_files.php`, PHPUnit's `Assert/Functions.php`, `Mockery.php`, Mockery's `helpers.php`, `deep_copy.php` and Brain Monkey's `api.php` were `include`d on **every** front-end and admin request — wasted I/O, and a collision risk since Mockery defines global `mock()`, `spy()` and `namedMock()` functions. That file is now down to 7 small polyfill entries with nothing dev-related. Dev dependencies install into a separate, git-ignored `vendor-dev/` tree (`COMPOSER_VENDOR_DIR=vendor-dev composer install`), so they cannot leak back into the shipped tree.
* **Smaller autoloader parsed on every request.** `vendor/composer/autoload_static.php` shrinks from 415 KB to 62 KB and `autoload_classmap.php` from 384 KB to 55 KB (3,013 entries down to 499). The unused `autoload.classmap` entry for `includes/` was also removed — every plugin class is loaded by an explicit `require_once`, so it only ever added dead classmap entries.
* **Backups no longer archive the plugin's own `vendor/` directory.** `Mighty_Backup_File_Archiver::DEFAULT_EXCLUSIONS` now contains `mighty-backup/vendor`, so the backup walker prunes that subtree instead of stat-ing, reading and gzipping thousands of dependency files into every archive — and then walking them a second time during archive verification. Note that Mighty Backup will report its dependencies as missing inside a Codespace built from one of these archives; `composer install --no-dev` in the plugin directory restores them, and `composer.json`/`composer.lock` still ship in the release ZIP for exactly that purpose.
* **Added CI guards.** A new `vendor-guard` workflow fails a pull request if dev dependencies get committed, if unused AWS services reappear, if `autoload_files.php` regains a dev entry, if the committed classmap references deleted files, or if the tracked `vendor/` file count climbs back above 900. The release workflow now verifies the pruned tree and boots the SDK — constructing the client, presigning a URL and building both paginators — before it will publish a ZIP.

= 2.15.0 =
**Devcontainer updater improvements — three fixes to the Devcontainer tab's update flow.**

* **Preserve the repo's `hostRequirements.cpus` on a version upgrade.** Previously, updating `.devcontainer` to a newer template version rewrote `devcontainer.json` with `cpus` recomputed from the site's current disk size, silently discarding a repo that had been tuned to (or previously resized to) a larger machine. `Mighty_Devcontainer_Manager::install_or_update()` now reads the destination repo's existing `cpus` (via `check_version()`, which now returns `current_cpus` on the outdated path too) and preserves it, only falling back to the disk-size-derived tier when the repo has no `cpus` set. The separate `create_size_update()` "site outgrew its machine" resize path is unchanged, so genuine growth is still detected and offered as its own PR on the next up-to-date check. The update PR body now states whether `cpus` was preserved or computed.
* **Stop backups from rolling back a devcontainer upgrade.** The file archiver captured everything under `ABSPATH` and did not exclude `.devcontainer/` (only `.git` and `node_modules`), so the source site's stale `.devcontainer/` rode along in the backup and, on Codespace bootstrap/import, overwrote the repo's freshly-upgraded config — the pre-upgrade status page reappeared even though the repo/PR had updated correctly. `.devcontainer` is now in `Mighty_Backup_File_Archiver::DEFAULT_EXCLUSIONS`, applied to both the shell-`tar` and PHP-walker capture paths. The GitHub repo is the source of truth for `.devcontainer/`; the Codespace clones it from the repo, and backups no longer carry a competing copy.
* **Live progress bar for the devcontainer update.** The update was a single blocking request that only disabled the button and showed a frozen "Creating PR…" label for the ~20-30 sequential GitHub API calls. It now shows a determinate progress bar with percentage and estimated time remaining, driven by a dedicated progress channel (`Mighty_Backup_Log_Stream::set_devcontainer_progress()`, separate from the backup progress so the two never collide) that the admin JS polls concurrently. The per-file blob-copy loop reports "Copying file X of N".

= 2.14.1 =
* Fixed an `open_basedir` warning emitted by the bundled AWS SDK on every S3/Spaces client construction (twice per Action Scheduler queue run on affected hosts). The SDK's shared-config resolver probed `$HOME/.aws/config`, which on hosts like Sevalla resolves to a path outside the `open_basedir` allow-list (e.g. `/www/<site>/.aws/config`), tripping `is_readable()`. `Mighty_Backup_Spaces_Client` already passes credentials, region, and endpoint explicitly, so the shared config/credentials files were never needed; the client now sets `use_aws_shared_config_files => false`, which stops the probe entirely. No credential or behavior change — uploads, listing, and signing are unaffected. (VAL-892)

= 2.14.0 =
**Bundled audit fixes — six independent PRs landing in one release. Focused entirely on the Codespace-producer scope; no new operational surface.**

* **(PR1) No-PK large-table handling — Hybrid B→D degradation chain.** Before: a big table without a single-column numeric `PRIMARY KEY` got its data SKIPPED entirely in the chunked-mysqldump path (only the `CREATE TABLE` survived) — WooCommerce extension tables and audit/log tables shipped empty in the Codespace bootstrap snapshot. Now `Mighty_Backup_Database_Exporter::get_table_cursor_column()` walks `PRIMARY KEY → single-column UNIQUE NOT NULL → auto_increment` and feeds the existing range pipeline; numeric cursors compute the next range bound arithmetically, non-numeric cursors use `next_cursor_upper_bound()`'s indexed `LIMIT-N` seek. When no cursor exists at all, `dump_table_full_via_mysqldump()` runs one mysqldump invocation for that table in its own Action Scheduler chunk with `set_time_limit(0)`. The PHP-path `export_table_data_offset()` fallback now wraps its loop in `REPEATABLE READ + START TRANSACTION WITH CONSISTENT SNAPSHOT` on InnoDB so concurrent writes can't shift rows across pages.
* **(PR2) State-machine race cluster.** Four compounding reliability bugs resolved: (a) TOCTOU in `schedule()` is now atomic via `add_site_option()` as a CAS primitive — concurrent triggers from wp-cron + admin "Run Now" + WP-CLI get a single winner instead of stomped state. (b) `is_running()` now returns true for `pending` AND `cancelling` statuses, closing multi-second race windows. (c) `cancel()` writes a `cancelling` tombstone BEFORE deleting actions; `fail()` re-reads state at entry and skips the resurrecting save when it sees the tombstone. (d) `step_start` / `step_export_db` / `step_archive_files` / `step_upload_db` / `step_upload_files` / `step_cleanup` all early-exit when their result-signal key is already set, preventing duplicate multi-GB Spaces uploads on Action Scheduler re-fires after a worker SIGKILL.
* **(PR3) Scheduler correctness.** Three direct hits to the stated purpose: (a) registered the `weekly` recurrence via the `cron_schedules` filter — WordPress core only ships `hourly`/`twicedaily`/`daily`, and without this filter `wp_schedule_event('weekly', ...)` silently returned false, so an operator who chose Weekly got ZERO backups. (b) `calculate_next_run()` now honors `schedule_day` for the weekly recurrence — the setting was stored but ignored. (c) Replaced the WP-5.3-deprecated `current_time('timestamp')` + `strtotime()` combo with `wp_timezone()` + `DateTimeImmutable`, fixing the DST-day drift where the next fire could shift by an hour twice a year.
* **(PR4) DigitalOcean Spaces multipart-upload lifecycle.** Cancelling a backup mid-upload, or any final-attempt `MultipartUploadException`, used to leave the already-uploaded parts on Spaces — for a 20 GB archive at 5 MB parts that's ~4,000 orphan parts per cancel, billed indefinitely. Three-layer defense: `Spaces_Client::upload()` now calls `abortMultipartUpload` via the `MultipartUploadException`'s state when the final retry fails; `ensure_lifecycle_policy()` idempotently installs an `AbortIncompleteMultipartUpload` rule (Days: 1) under the `client_path/` prefix on plugin activation; `sweep_orphan_multiparts()` lists multiparts older than 24h via `ListMultipartUploads` and aborts them, wired into the daily retention cron.
* **(PR5) Retention correctness + state-shape hardening.** Three small fixes bundled: (a) `Retention_Manager::prune_prefix()` now sorts by the backup-`{Y-m-d-His}` key prefix (lexicographically monotonic) instead of trusting Spaces' `LastModified` (which isn't globally monotonic under clock skew), and refuses to delete anything `LastModified < 1h` ago. Zero-objects-under-prefix now emits a "verify client_path setting" warning since the common cause is a rename orphaning the old prefix. (b) `Mighty_Backup_Manager::validate_db_export_shape()` is called from the top of `step_export_db` — catches a `db_export` sub-state in the wrong shape (e.g., a backup in flight at upgrade time) and fails with a clear message instead of dereferencing missing keys deep in the run loop. (c) `process_next_action()` now catches `\Throwable` (was `\Exception`) so a `TypeError` inside an action can't skip `release_claim()` and wedge the Action Scheduler claim globally for every AS user on the install.
* **(PR6) State-bloat + history retention polish.** Three quality-of-life fixes: (a) `save_state()` writes `STATE_OPTION` with `autoload=no` on first creation on single-site installs, so the 50–100 KB serialized backup state isn't loaded on every wp-admin page hit. Multisite is unaffected (uses `wp_sitemeta` which has no autoload concept). (b) `Mighty_Backup_Logger::prune_history()` deletes history rows older than 90 days (filterable via `mighty_backup_history_retention_days`); wired into the daily retention cron. (c) The retention cron also sweeps stale `completed` STATE_OPTION rows older than 72h — the post-backup state currently lingers until the next `schedule()` call clears it, so an install that runs a backup once and stops would carry the stale option forever.

= 2.13.2 =
* Fixed `count(): Argument #1 ($value) must be of type Countable|array, null given` fatal in `Mighty_Backup_Manager::get_status()` on PHP 8 when polling status during a chunked-mysqldump database export (regression introduced in 2.13.0). The chunked path's `$state['db_export']` shape uses `big_tables` / `big_tables_index` keys, but `get_status()` was still reading the PHP path's `tables` / `tables_exported` keys, fataling on the absent `tables` key. Now branches on `db_export['method']` and guards both shapes with `?? null` + `is_array()` so a malformed state can never re-introduce the fatal. Affected callers: `wp mighty-backup status`, the admin-UI AJAX polling, and the WP-CLI poll loop in `wp mighty-backup run`.

= 2.13.1 =
* Fixed PHP-path database export leaking persisted wpdb placeholder-escape hashes (cross-session `{HASH}` tokens). `build_values_string()` previously called `$wpdb->remove_placeholder_escape()`, which only matches the *current* request's session hash via `str_replace` — tokens minted by prior WordPress sessions fell through verbatim into the dump. The PHP-path row writer now uses the regex-based `Mighty_Backup_Placeholder_Repair::sanitize_string()` already used by the mysqldump streaming path, which matches `\{[a-f0-9]{64}\}` uniformly and also recomputes `s:N:"…"` length prefixes for serialized payloads (ACF flexible content, etc.). Net effect on poisoned databases: backup files come out clean even when the source DB still has tokens.
* Changed: the `placeholder_strips` counter (surfaced in the live-log warning) now also counts persisted-hash strips. Existing operators will see step-function increases the first time a 2.13.1 export runs over a corrupted DB — this is the new baseline, not a regression. The accompanying log message has been updated to reflect that the backup is clean and to recommend `wp mighty-backup repair placeholders --dry-run` only for cleaning the source DB.
* Added: per-row filter dispatch is now cached once per export run so the `mighty_backup_sanitize_placeholder_hashes` filter still works as a kill switch without paying `apply_filters` overhead on every column value.

= 2.13.0 =
* Added mid-table resumable PHP database export — `export_table_data_pk()` now time-checks between SQL batches and persists `{current_table, current_table_pk, current_table_last_pk}` in `db_export` state so a single large table can be sliced across multiple Action Scheduler chunks; survives any reasonable `max_execution_time` cap (the load-bearing fix for 9 GB single-table sites on hosts like Kinsta)
* Added range-chunked mysqldump path — tables exceeding `db_large_table_threshold_mb` (default 1 GB) are dumped as a sequence of `mysqldump --where='pk > A AND pk <= B'` invocations sized adaptively from the previous range's wall-time; small tables continue to flow through a single mysqldump invocation, and streamlined mode automatically falls back to the chunked PHP path so order/log filtering still applies
* Added pre-flight per-table size snapshot — one `information_schema` query at the start of a chunked export populates the live log with `Table wp_postmeta: 9.0 GB` per table-start, making "stuck on big table" obvious in the UI
* Added Advanced settings disclosure in the Schedule tab — `Chunk Seconds` (10–300, default 30) and `Large-Table Threshold` (128–10240 MB, default 1024) exposed via the existing form; the long-standing `mighty_backup_db_chunk_seconds` filter still wins over the option for `wp-config.php` overrides
* Added error-translator remediation hints for `Maximum execution time of N seconds exceeded` and "table has no primary key" — both deep-link into Settings → Schedule with concrete next steps (raise Chunk Seconds, mark structure-only, or add a PK)
* Fixed `get_large_tables()` skipping structure-only tables — a 9 GB log table marked structure-only would previously have been range-dumped, producing duplicate `CREATE TABLE` statements and exporting the data the user explicitly suppressed
* Fixed CLI `coerce_value` honoring the new `max` field in `KEY_META` — `wp mighty-backup settings set db_chunk_seconds 9999` is now rejected with a clear out-of-range message, matching the web UI clamp
* Fixed `finalize_mysqldump_chunked()` no longer appending the PHP-path postamble (`SET FOREIGN_KEY_CHECKS=1; COMMIT;`) when the placeholder-sanitization filter is disabled — mysqldump's own state-restore block stays authoritative

= 2.12.0 =
* Added onboarding wizard (`admin/views/onboarding-wizard.php`) — five-step chip flow covering Storage Credentials, GitHub Integration, Backup Schedule, Codespace Settings, and Notifications, so new installs see a guided setup instead of an empty settings page
* Added error translator (`includes/class-error-translator.php`) — maps raw exception messages and AWS error codes to human-readable explanations with remediation hints and deep-links into the relevant settings tab, surfaced across the live log, backup history, and AJAX response banners
* Added backup history logger (`includes/class-logger.php`) — custom database table records every backup with type, trigger source, status, timing, file sizes, remote object keys, and error messages for audit/troubleshooting
* Added per-table database export controls — settings page lets you exclude specific tables outright or export them structure-only (schema, no rows); new tables are included by default unless explicitly opted out
* Added tar archive post-verification — file archiver walks the tar.gz end-to-end after creation (shell `tar -tzf` when available, streaming PHP gzip+tar header parser as fallback) to catch archive desync before upload to Spaces
* Fixed database exporter respecting per-table overrides — exclusions and structure-only flags now propagate through the chunked export path, with skipped tables removed from progress counts so the bar no longer overshoots

= 2.10.0 =
* Added independent daily retention cron (`mighty_backup_retention`) — decouples cleanup from backup success so a streak of failed nightly backups can no longer let old objects accumulate on Spaces; the in-chain `step_cleanup` is preserved as the optimal "right after a fresh backup" prune
* Added "Retention last ran" diagnostic line on the Schedule tab — shows when retention last fired and how many database/file backups were removed (or the error message if it failed)
* Added "Last synced" status under the "Push as Codespaces Secret" button — persists owner/repo/timestamp from the most recent successful push (manual or auto) and renders via `human_time_diff()`; updates immediately on successful push without a page reload
* Added 32-core / 256 GB GitHub Codespaces tier to the devcontainer sizing logic — sites with raw size between 107 GB and 213 GB now correctly map to 32-core instead of falling back to a 16-core warning; size warning threshold raised accordingly
* Fixed `calculate_site_disk_size()` silently returning 0 (and thus selecting the smallest 4-core tier) when the iterator hit any unreadable subdirectory — the recursive walker now uses `CATCH_GET_CHILD` to skip unreadable subtrees, per-entry stat errors are caught individually, and root-inaccessibility surfaces as a `RuntimeException` instead of a phantom 0
* Fixed `cpus_to_disk_gb()` mapping for 2-core (now correctly reports 32 GB instead of 16 GB) and added explicit 32-core (256 GB) arm — only affects PR-body disk-size hints, but no longer misleads
* Removed the show/reveal toggle from the GitHub PAT field — the token now stays masked at all times (DO Spaces Access Key and Secret Key fields keep their toggles)

= 2.9.0 =
* Fixed `wpdb::placeholder_escape()` corruption at the source — the PHP database export path now strips `{<64-hex>}` placeholder tokens from every row before writing, so backups created from sites with `%` characters in user data no longer bake the session hash into the dump
* Added serialize-aware sanitize pass to the mysqldump and streamlined-hybrid export paths — strips persisted `{HASH}` tokens from the dump on the way out, recomputing `s:N:"…"` length prefixes; gated by the new `mighty_backup_sanitize_placeholder_hashes` filter (default true) so debugging admins can capture an unmodified dump
* Added `wp mighty-backup repair placeholders [--dry-run] [--no-backup-first]` — scans `options`, `posts`, and core `*meta` tables for persisted placeholder tokens, takes a pre-flight backup, repairs in place via raw `UPDATE` (bypassing `update_option()` so `%` is not re-escaped), and flushes object/page caches (LiteSpeed, WP Rocket, W3TC, plus the new `mighty_backup_after_repair_flush` action)
* Added authed `GET /wp-json/mighty-backup/v1/healthcheck` REST endpoint — Bearer-token authenticated like `/codespace-config`, exposes a `placeholder_hash_corruption` summary (count + sample location) so monitoring and the codespace bootstrap can detect persisted hashes BEFORE they end up in a backup
* Added automatic push of `BM_BOOTSTRAP_KEY` to the configured GitHub repo as a Codespaces secret — fires when a new API key is generated or when GitHub owner/repo/PAT change in the Devcontainer tab; silent best-effort, errors logged to the live backup log without blocking the originating action
* Fixed devcontainer hostRequirements update overwriting sibling fields — `cpus` is now patched in place, preserving any `memory`/`storage` keys that the template declares

= 2.8.0 =
* Added `wp mighty-backup api-key push-secret` — pushes `BM_BOOTSTRAP_KEY` to the configured GitHub repo as an encrypted Codespaces or Actions secret using libsodium sealed-box encryption against the repo's public key

= 2.7.1 =
* Fixed tar exit code 1 ("file changed as we read it") being treated as fatal failure during file archival — exit 1 is a non-fatal warning expected on live sites with active caches, sessions, and logs; only exit code 2+ is now treated as failure

= 2.7.0 =
* Fixed mysqldump false-failure on MariaDB hosts — the MariaDB `mysqldump` shim prints a deprecation warning to stderr on every call; the plugin no longer treats non-empty stderr as failure (gates on exit code only)
* Added `mariadb-dump` binary detection — prefers `mariadb-dump` over `mysqldump` when available, avoiding the deprecation warning entirely and forward-compatible with MariaDB dropping the mysqldump shim
* Added `set -o pipefail` to the mysqldump-to-gzip pipe so dump failures propagate correctly through the pipe

= 2.6.0 =
* Fixed DigitalOcean Spaces secret key not saving on initial save (double-sanitization bug when option did not yet exist) — same fix applied to the GitHub PAT
* Added target-branch selector for devcontainer update PRs — branches are fetched from GitHub during version check and presented as a dropdown (defaulting to the repo's default branch)
* Added `wp mighty-backup settings list|get|set` WP-CLI commands for managing all plugin settings from the command line, with transparent encryption of the DO Spaces secret and GitHub PAT and masked output unless `--show-secret(s)` is passed
* Added `wp mighty-backup api-key generate|show|delete` WP-CLI commands for managing the Codespace bootstrap API key (`bm_backup_api_key`) from the command line
* Added `wp mighty-backup devcontainer check|update` WP-CLI commands for checking the .devcontainer version and creating update PRs (with `--branch` targeting and `--yes` for unattended runs)

= 2.5.0 =
* Fixed backups hanging at "waiting for background processing" in both admin UI and WP-CLI
* Backup actions are now processed directly during status polling, removing dependency on WP-Cron and Action Scheduler's async dispatcher
* Prevents stale Action Scheduler claims from blocking backup execution

= 2.4.0 =
* Chunked database export — PHP-based DB exports now split across multiple Action Scheduler actions for reliable large database backups
* Time-bounded chunks (default 30 seconds per action, filterable via `mighty_backup_db_chunk_seconds`)
* Sub-progress reporting during DB export (shows table count and smooth progress bar interpolation)
* Fixed GitHub update checker URL — plugin updates now work correctly
* Stored update checker instance and set branch to `main` for correct fallback behavior
* Fixed admin UI step indicator pills not highlighting during backup (was referencing wrong property)
* Devcontainer size check now runs automatically during version check — detects when site has outgrown its Codespace tier
* Devcontainer resize creates a targeted PR updating only `hostRequirements.cpus` when version is current but machine is too small
* Codespace tier selection now includes 20% disk headroom
* Devcontainer hostRequirements now sets only `cpus` (storage is implicit with core count on GitHub Codespaces)

= 2.3.0 =
* Devcontainer updates now set hostRequirements (cpus/storage) in devcontainer.json based on site disk size (excluding uploads)
* Added admin warning notice on settings page when site exceeds the 128 GB GitHub Codespace limit
* PR body now includes Codespace tier and site size information

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
