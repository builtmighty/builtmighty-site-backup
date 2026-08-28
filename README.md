# Mighty Backup

A WordPress plugin that automates site backups to DigitalOcean Spaces. It supports scheduled and on-demand backups of both the database and file system, with built-in integration for the staged-loader Codespace pipeline.

[![Download ZIP](https://img.shields.io/badge/Download-ZIP-blue?style=for-the-badge)](https://github.com/builtmighty/mighty-backup/releases/latest/download/mighty-backup.zip)

## Install via WP-CLI

```bash
wp plugin install https://github.com/builtmighty/mighty-backup/releases/latest/download/mighty-backup.zip --activate
```

## Requirements

- WordPress 6.0+
- PHP 8.1+

## Features

- **Full, Database, or Files-only backups** — choose what to back up
- **Scheduled backups** — daily, twice daily, or weekly via WP-Cron
- **DigitalOcean Spaces storage** — multipart uploads with retry and resume
- **Streamlined Mode** — lighter database exports that filter WooCommerce orders to the last 90 days and export log tables as structure only
- **Live backup log** — real-time progress display with timestamped entries during backup
- **Retention management** — automatically prunes old backups beyond a configurable limit; runs both at the end of every successful backup *and* via an independent daily cron (`mighty_backup_retention`) so a streak of failed nightly backups can never accumulate orphaned objects on Spaces
- **Backup history** — logs every backup with status, sizes, and errors
- **Email notifications** — alerts on backup failure
- **Dev Mode detection** — prevents dev/staging sites from overwriting production backups
- **Codespace integration** — REST API endpoint and bootstrap key for the pipeline
- **Devcontainer management** — check and update .devcontainer config via GitHub API. An update replaces `.devcontainer/` wholesale on a `update-devcontainer-v{version}` branch, carrying over only the repo's `hostRequirements.cpus`; automatic Codespace tier sizing (4 → 8 → 16 → 32-core, up to 256 GB) based on site disk usage with 20% headroom fills in when the repo has none set, and a separate resize PR is raised when a site outgrows its current tier
- **Self-driving backup processing** — backup steps are processed directly during admin UI polling and WP-CLI execution, with no dependency on WP-Cron or Action Scheduler's async dispatcher
- **WP-CLI support** — full command-line interface with timeout control
- **Automatic updates** — auto-updates from GitHub releases via built-in update checker
- **MariaDB compatible** — prefers `mariadb-dump` when available, filters benign deprecation warnings, and uses `set -o pipefail` for robust pipe error detection
- **Pressable & managed hosting compatible** — handles split ABSPATH/WP_CONTENT_DIR, follows symlinked plugins, secure mysqldump via defaults file
- **Multisite compatible** — settings stored at the network level
- **Cancel in-progress backups** — stop a running backup from the admin UI or WP-CLI
- **Developer filters** — tune batch size, part size, concurrency, and gzip levels via `add_filter()`
- **Action hooks** — fire custom code before/after each backup step, on completion, or on failure
- **Access-controlled settings** — settings page restricted to authorized `@builtmighty.com` accounts

## Installation

1. Upload the plugin to `wp-content/plugins/mighty-backup`.
2. Activate the plugin in WordPress (or Network Activate on multisite).
3. Go to **MightyBackup** in the admin menu and configure your DigitalOcean Spaces credentials.

`vendor/` is committed, so no `composer install` is needed for a normal install. If `vendor/` is ever damaged, repair it with `composer install --no-dev --optimize-autoloader` from the plugin directory.

## Local Development

`vendor/` is **committed and pruned**, and dev dependencies install into a separate, git-ignored `vendor-dev/` tree. This matters: Composer records each package's bootstrap files in `vendor/composer/autoload_files.php`, and the plugin loads `vendor/autoload.php` on every request — so a dev-flavoured `vendor/` would `include` PHPUnit's assertion helpers, `Mockery.php` and Brain Monkey's `api.php` on every front-end page load, and Mockery would define global `mock()` / `spy()` / `namedMock()` functions that can collide with other plugins.

**Install dev dependencies and run the tests:**

```bash
# bash
COMPOSER_VENDOR_DIR=vendor-dev composer install
vendor-dev/bin/phpunit
```

```powershell
# PowerShell
$env:COMPOSER_VENDOR_DIR = "vendor-dev"; composer install
vendor-dev\bin\phpunit
```

`tests/bootstrap.php` prefers `vendor-dev/autoload.php` and falls back to `vendor/autoload.php`.

**When a production dependency changes**, regenerate the shipped tree and commit it:

```bash
composer install --no-dev --optimize-autoloader   # note: no COMPOSER_VENDOR_DIR
git add -A vendor composer.json composer.lock
```

That command also re-runs the AWS service pruner, so `vendor/` stays at ~720 files. The `vendor-guard` workflow fails the PR if dev dependencies get committed, if unused AWS services reappear, if `autoload_files.php` regains a dev entry, if the committed classmap points at deleted files, or if the tracked `vendor/` file count exceeds 900.

### Bundled AWS SDK is pruned to S3 only

`composer.json` declares which AWS services survive:

```json
"extra": { "aws/aws-sdk-php": ["S3"] }
```

and the SDK's own `Aws\Script\Composer\Composer::removeUnusedServices` runs on `pre-autoload-dump`, deleting the other 406 services' client and model directories. The pruner also force-keeps `Kms`, `SSO`, `SSOOIDC`, `Sts` and `Signin` (~171 KB), which the credential-provider chain can reach.

> **The prune is one-way.** Composer decides what to install from `vendor/composer/installed.json`, not from what is on disk, so a plain `composer install` will **not** restore a deleted service. To add a service, list it in `extra` **and** run `composer reinstall aws/aws-sdk-php`. `rm -rf vendor/aws && composer install` is not reliable.

> **The hook must stay `pre-autoload-dump`.** On `post-autoload-dump` the classmap is generated against the un-pruned tree, leaving ~2,500 entries pointing at deleted files and producing `include(): Failed opening` warnings in `debug.log`.

### Why `vendor/` is committed

The plugin auto-updates through [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker), configured with `setBranch('main')` and **without** `enableReleaseAssets()`. With that configuration PUC resolves the latest release and downloads GitHub's auto-generated **source zipball** — not the `mighty-backup.zip` release asset. So the committed tree is what every updating site receives, and removing `vendor/` from git would leave updated sites with no AWS SDK and no Action Scheduler, silently stopping backups.

Note also that PUC's `REQUIRE_RELEASE_ASSETS` is not strict: when no asset matches it returns `null` and the checker falls through to the latest **tag**, which is again a source zipball. Keeping `vendor/` committed makes every channel — release zipball, tag zipball, branch archive, CI release asset, and `git clone` — produce an identical working plugin, and keeps rollback a plain `git revert` plus a patch release.

## Configuration

All settings are managed from the admin settings page.

| Setting | Description |
|---|---|
| **Spaces Access Key** | DigitalOcean Spaces access key |
| **Spaces Secret Key** | Secret key (encrypted at rest with AES-256-CBC) |
| **Spaces Endpoint** | e.g. `nyc3.digitaloceanspaces.com` |
| **Spaces Bucket** | Bucket name |
| **Client Path** | Path prefix within the bucket |
| **Hosting Provider** | Pressable or Generic |
| **Schedule Frequency** | Daily, Twice Daily, or Weekly |
| **Schedule Time** | Time of day (HH:MM) |
| **Retention Count** | Number of backups to keep (1–365, default 7) |
| **File Exclusions** | Additional patterns to exclude (one per line) |
| **Notify on Failure** | Send email alerts when a backup fails |
| **Notification Email** | Custom recipient (defaults to site admin) |

### Default File Exclusions

The following paths are always excluded from file backups:

- `wp-content/uploads`
- `wp-content/cache`
- `wp-content/upgrade`
- `wp-content/backups`
- `wp-content/backup-db`
- `.git`
- `node_modules`
- `.devcontainer` (owned by the GitHub repo, which is the source of truth)
- `wp-content/updraft` (UpdraftPlus)
- `wp-content/ai1wm-backups` (All-in-One WP Migration)
- `wp-content/backups-dup-lite` (Duplicator)
- `wp-content/backups-dup-pro` (Duplicator Pro)
- `wp-content/object-cache.php` (production drop-in)
- `wp-content/advanced-cache.php` (production drop-in)
- `wp-content/mysql.sql` (hosting-managed SQL snapshot — redundant and racy)
- `mighty-backup/vendor` (this plugin's own Composer tree — see note below)

> **Note on `mighty-backup/vendor`.** These archives exist to hydrate Codespaces, not to restore production, so the plugin's own dependencies are not archived — that avoids stat-ing, reading and gzipping hundreds of dependency files into every archive, and walking them again during archive verification. Consequence: Mighty Backup will report its dependencies as missing inside a Codespace built from one of these archives. Run `composer install --no-dev` in the plugin directory to restore them; `composer.json` and `composer.lock` ship in the release ZIP for exactly this purpose.
>
> The pattern is `mighty-backup/vendor` rather than a bare `vendor` on purpose: exclusions match a whole path segment anywhere in the path, so a bare `vendor` would strip **every** plugin's and theme's dependencies from every backup.

## WP-CLI Commands

```bash
# Run a backup (synchronous by default)
wp mighty-backup run [--type=<full|db|files>] [--async] [--timeout=<seconds>]

# Check backup status
wp mighty-backup status

# Cancel a running or pending backup
wp mighty-backup cancel

# List backups stored on Spaces
wp mighty-backup list [--type=<all|db|files>]

# Manually trigger retention cleanup
wp mighty-backup prune

# Test the Spaces connection
wp mighty-backup test

# Show / exit dev mode
wp mighty-backup dev-mode [--disable]

# Repair persisted wpdb placeholder-escape tokens (`{HASH}`) in the database
wp mighty-backup repair placeholders [--dry-run] [--no-backup-first]
```

#### `repair placeholders`

Scrubs persisted `{<64-hex>}` tokens left behind when wpdb's session-scoped
`placeholder_escape()` value gets stored back into the database (e.g. via an
export → import round-trip of `get_results()` output that didn't pass through
`remove_placeholder_escape()`). The command:

1. Detects whether the database contains any persisted tokens.
2. Counts corrupted rows across `options`, `posts` (content / title /
   excerpt), and the core `*meta` tables, plus multisite `*_options`.
3. With `--dry-run`, prints the per-table count and exits without writing.
4. Otherwise, takes a pre-flight `db` backup (skip with `--no-backup-first`),
   issues raw `UPDATE`s — bypassing `update_option()` etc. so `%` is not
   re-escaped — recomputes `s:N:"…"` length prefixes for any affected
   serialized strings, flushes object/page caches, and verifies zero rows
   remain.

### Settings Management

All plugin settings can be read and written from the CLI. Encrypted fields
(`spaces_secret_key`, `github_pat`) are encrypted transparently on `set` and
masked in `list` / `get` output unless you opt-in with `--show-secret(s)`.

```bash
# List every setting (encrypted fields shown as ••••••••)
wp mighty-backup settings list [--format=<table|json|yaml|csv>] [--show-secrets]

# Read a single setting
wp mighty-backup settings get <key> [--show-secret]

# Write a single setting (booleans accept 1/0, true/false, yes/no, on/off)
wp mighty-backup settings set <key> <value>
```

Examples:

```bash
wp mighty-backup settings set spaces_access_key "DO00XXXX..."
wp mighty-backup settings set spaces_secret_key "s3cret-v@lue"
wp mighty-backup settings set spaces_endpoint nyc3.digitaloceanspaces.com
wp mighty-backup settings set spaces_bucket my-bucket
wp mighty-backup settings set client_path my-client-repo
wp mighty-backup settings set schedule_frequency weekly
wp mighty-backup settings set schedule_day monday
wp mighty-backup settings set schedule_time 03:00
wp mighty-backup settings set retention_count 14
wp mighty-backup settings set notify_on_failure 1
wp mighty-backup settings set notification_email ops@example.com
wp mighty-backup settings set github_pat "ghp_NEWVALUE"
```

Writable keys: `spaces_access_key`, `spaces_secret_key`, `spaces_endpoint`,
`spaces_bucket`, `client_path`, `hosting_provider`, `schedule_frequency`,
`schedule_time`, `schedule_day`, `retention_count`, `extra_exclusions`,
`notify_on_failure`, `notification_email`, `streamlined_mode`, `github_owner`,
`github_repo`, `github_pat`, `php_version`, `db_engine`, `db_version`,
`timezone`, `multisource`, `multisource_name`.

The last six shape the Codespace config payload. All are optional — leaving one
blank reports the value detected from the live server. See
[Codespace Config](#codespace-config).

### Devcontainer

Manage the repo's `.devcontainer` configuration via the GitHub API — the CLI
equivalent of the Devcontainer tab in the admin UI.

```bash
# Check current vs. latest version
wp mighty-backup devcontainer check [--format=<table|json|yaml>]

# Create a PR to install or update .devcontainer
wp mighty-backup devcontainer update [--yes]
```

`--yes` skips the confirmation prompt (useful for automation).

#### What an update PR contains

The PR branches from the repo's default branch as
`update-devcontainer-v{version}` and replaces `.devcontainer/` **wholesale**:

1. The repo's existing `hostRequirements.cpus` is recorded.
2. Every file under `.devcontainer/` is removed — **including `setup/`**, which
   earlier versions preserved. Files the repo had that the template does not
   ship are listed by path in the PR body so they can be restored deliberately.
3. The new template's `.devcontainer/` is added in full.
4. `hostRequirements.cpus` is restored to the recorded value, by rewriting that
   one value in place so the file's comments and formatting survive.

Only `cpus` carries over. `memory` and every other key come from the new
template — the template ties `memory` to the `innodb_buffer_pool_size` in its
own `db/*.cnf`, so pinning a stale value can leave the database OOM-killed
partway through an import.

Nothing outside `.devcontainer/` is touched. If GitHub truncates the file
listing for either repo the update aborts rather than commit a partial
directory.

### Codespace Bootstrap API Key

The `bm_backup_api_key` option authenticates the Codespace config REST
endpoint. The printed "bootstrap key" is what you paste into the
`BM_BOOTSTRAP_KEY` Codespace secret.

```bash
# Generate (or regenerate) the API key — prints the bootstrap key
wp mighty-backup api-key generate

# Show the current bootstrap key (add --raw for the raw API key)
wp mighty-backup api-key show [--raw]

# Delete the API key (disables the Codespace config endpoint)
wp mighty-backup api-key delete
```

#### Automatic secret push

When the GitHub owner, repo, and PAT are all configured under the
**Devcontainer** tab, the plugin automatically pushes `BM_BOOTSTRAP_KEY` to
the configured repo as a Codespaces secret whenever:

- A new API key is generated (the bootstrap key has changed), or
- The Devcontainer settings are saved with a new owner/repo/PAT.

The push is silent and best-effort — failures are logged via the live
backup log and never block the originating action. To push manually (or to
override the secret name / target), use `wp mighty-backup api-key push-secret`.

The Codespace settings tab shows a "Last synced to {owner}/{repo} · N ago"
line under the **Push as Codespaces Secret** button, sourced from the most
recent successful push (manual or automatic). The line updates immediately
after a successful push without requiring a page reload.

## Developer Hooks & Filters

### Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `mighty_backup_db_batch_size` | `1000` | Rows per paginated DB export query |
| `mighty_backup_db_gzip_level` | `3` | Gzip compression level for DB dump (1–9) |
| `mighty_backup_files_gzip_level` | `3` | Gzip compression level for file archive (1–9) |
| `mighty_backup_upload_part_size` | `26214400` | Multipart upload part size in bytes (25 MB) |
| `mighty_backup_upload_concurrency` | `5` | Concurrent upload parts |
| `mighty_backup_upload_max_retries` | `3` | Max upload retries per part |
| `mighty_backup_admin_domains` | `['builtmighty.com']` | Email domains permitted to access the settings page |
| `mighty_backup_streamlined_days` | `90` | Days of WooCommerce orders to include in streamlined mode |
| `mighty_backup_is_log_table` | `(bool)` | Override whether a table is treated as a log table in streamlined mode |
| `mighty_backup_order_table_config` | `(array)` | Override the order table → ID column mapping in streamlined mode |
| `mighty_backup_db_chunk_seconds` | `30` | Max seconds per Action Scheduler action during chunked PHP database export |
| `mighty_backup_sanitize_placeholder_hashes` | `true` | Strip persisted `{<64-hex>}` wpdb placeholder tokens from the SQL dump on the way out. Set to `false` to capture an unmodified dump for debugging. |
| `mighty_backup_placeholder_scan_targets` | `(array)` | Override the list of `[table, pk, payload_column]` tuples scanned by the repair command and the authed healthcheck. |

### Action Hooks

| Hook | Args | Description |
|------|------|-------------|
| `mighty_backup_before_start` | `$state` | Fires at the top of the start step |
| `mighty_backup_after_start` | `$state` | Fires after the start step |
| `mighty_backup_before_export_db` | `$state` | Fires before DB export |
| `mighty_backup_after_export_db` | `$state, $db_path` | Fires after DB export |
| `mighty_backup_before_archive_files` | `$state` | Fires before file archive |
| `mighty_backup_after_archive_files` | `$state, $files_path` | Fires after file archive |
| `mighty_backup_before_upload` | `$state, $type` | Fires before each upload step |
| `mighty_backup_after_upload` | `$state, $type, $remote_key` | Fires after each upload step |
| `mighty_backup_completed` | `$state` | Fires when backup completes successfully |
| `mighty_backup_failed` | `$state, $error` | Fires when backup fails |
| `mighty_backup_after_repair_flush` | _(none)_ | Fires after `wp mighty-backup repair placeholders` flushes object/page caches — hook here for site-specific cache layers. |

## REST API

### Health Check

```
GET /wp-json/mighty-backup/v1/check
```

Public endpoint (no authentication required) that confirms the REST API is reachable. Returns plugin name, version, and timestamp. Also available as a one-click "Check API Health" button on the **Codespace** settings tab.

### Codespace Config

```
GET /wp-json/mighty-backup/v1/codespace-config
Authorization: Bearer <api-key>
```

Returns credentials and backup configuration for the Codespace bootstrap pipeline. HTTPS only, rate-limited to 10 requests per 60 seconds per IP. Note that the Spaces secret is returned **decrypted** — the API key is effectively a credential, so treat it as one.

A **Bootstrap Key** (available on the settings page) combines the site URL and API key into a single Base64-encoded secret for Codespace setup.

A flat JSON object, HTTP 200. Every value is a string except `multisource`.

```json
{
  "do_spaces_key":      "DO00ABCDEFGHIJKLMNOP",
  "do_spaces_secret":   "wJalrXUtnFEMI0K7MDENGbPxRfiCYEXAMPLEKEY",
  "do_spaces_endpoint": "nyc3.digitaloceanspaces.com",
  "do_spaces_bucket":   "builtmighty-backups",

  "client_path":        "acme-store",
  "repository":         "acme-store",
  "hosting_provider":   "generic",
  "remote_domain":      "acmestore.com",

  "php_version":        "8.2",
  "db_engine":          "mysql",
  "db_version":         "8.0.35",

  "multisource":        false,
  "timezone":           "America/Denver",
  "platform":           "wordpress",
  "source_name":        "backup"
}
```

| Field | Source | Consumed by |
|---|---|---|
| `do_spaces_key` | `spaces_access_key` | `~/.s3cfg` → `access_key` |
| `do_spaces_secret` | `spaces_secret_key` (decrypted) | `~/.s3cfg` → `secret_key` |
| `do_spaces_endpoint` | `spaces_endpoint`, normalized to a bare host | `~/.s3cfg` → `host_base`, and expanded into `host_bucket = %(bucket)s.<endpoint>` |
| `do_spaces_bucket` | `spaces_bucket` | `s3://<bucket>/<client_path>/…` |
| `client_path` | `client_path` (the repo slug) | The bucket prefix holding `databases/` and `files/` |
| `repository` | Same value as `client_path` | Retained for bootstraps predating the 2.10.0 rename |
| `hosting_provider` | `hosting_provider`, lowercased, defaults to `generic` | Only `pressable` changes behaviour (symlink repair + plugin reconcile) |
| `remote_domain` | Host of `get_site_url()` | URL-rewrite source and uploads-proxy target |
| `php_version` | `php_version` override, else detected `major.minor` | `php` version the container is built for (8.1–8.4) |
| `db_engine` | `db_engine` override, else detected | Which baked engine starts — `mysql` or `mariadb` |
| `db_version` | `db_version` override, else detected | Advisory; used to infer the engine when it is unset |
| `multisource` | `multisource` | Object-naming convention — see below. A real JSON boolean, not a string |
| `timezone` | `timezone` override, else detected IANA zone (falls back to `UTC`) | `date.timezone` |
| `platform` | Hardcoded | Recorded only |
| `source_name` | `Mighty_Backup_Settings::get_object_stem()` | The name this site's objects are keyed by — `backup` unless multisource is on |

The five environment fields (`php_version`, `db_engine`, `db_version`, `timezone`,
and the multisource site name) are auto-detected from the live server by
`Mighty_Backup_Environment`. Setting the matching option on the **Codespace** tab
overrides detection; leaving it blank reports the detected value, which is what
the field's placeholder shows.

#### Object layout

```
s3://builtmighty-backups/acme-store/databases/backup-2026-08-26-021500.sql.gz
s3://builtmighty-backups/acme-store/files/backup-2026-08-26-021500.tar.gz
```

With `multisource` enabled, several sites share one repository — and therefore
one bucket prefix — and are keyed by name instead of the generic `backup` stem:

```
s3://builtmighty-backups/acme-store/databases/store-us-2026-08-26-021500.sql.gz
s3://builtmighty-backups/acme-store/databases/store-eu-2026-08-25-013000.sql.gz
```

Retention lists objects with the stem in the prefix (`databases/<stem>-`), so one
site can never prune a sibling's history. Two things follow from that:

- Single-source behaviour is unchanged — the default stem makes the prefix
  `databases/backup-`, which every previously-uploaded object already matches.
- Enabling multisource leaves existing `backup-*` objects in place permanently.
  They fall outside retention and are never deleted; prune them by hand if they
  are no longer wanted.

### Authed Healthcheck

```
GET /wp-json/mighty-backup/v1/healthcheck
Authorization: Bearer <api-key>
```

Same Bearer-token auth as `codespace-config`, plus a
`placeholder_hash_corruption` summary so the Codespace bootstrap and external
monitoring can detect persisted wpdb hashes BEFORE they end up in a backup.
The unauthenticated `/check` endpoint is unchanged and continues to expose
only plugin name / version / timestamp.

## How It Works

Backups are executed as a chain of background steps via Action Scheduler:

1. **Start** — initialize backup, create log entry
2. **Export Database** — stream a gzipped SQL dump using primary-key pagination (binary columns exported as hex). When mysqldump is unavailable, the PHP export is automatically chunked across multiple Action Scheduler actions to avoid timeout and memory limits on large databases.
3. **Archive Files** — create a `tar.gz` archive (shell `tar` preferred, streaming PHP fallback); symlinked plugins are dereferenced and included. On hosts where `WP_CONTENT_DIR` is outside `ABSPATH` (e.g., Pressable), both locations are archived automatically. Files that change during archival (caches, sessions, logs) are handled gracefully — tar exit code 1 is logged as a non-fatal warning.
4. **Upload Database** — multipart upload to Spaces (25 MB parts, 5 concurrent)
5. **Upload Files** — multipart upload to Spaces
6. **Cleanup** — run retention policy, delete temp files, mark complete. An independent daily cron (`mighty_backup_retention`) also prunes Spaces regardless of whether a backup completed, so old objects can't accumulate when nightly backups are failing.

Each step runs independently to avoid timeout issues on resource-constrained hosts.

## Security

- Secret keys encrypted with AES-256-CBC using WordPress salts
- Optional `MIGHTY_BACKUP_SECRET` constant in `wp-config.php` adds a second pepper to AES-256-CBC key derivation (also accepts legacy `BM_BACKUP_SECRET` for backwards compatibility)
- Database credentials passed to mysqldump via temporary `--defaults-extra-file` (not visible in process lists or `/proc`)
- Credential fields are write-only — values never appear in page source or form fields
- Settings page restricted to authorized email domains (default: `@builtmighty.com`)
- REST API protected with Bearer token authentication
- HTTPS enforced on API endpoints
- Rate limiting on API access
- Nonce verification on all AJAX requests
- Capability checks (`manage_options` / `manage_network_options`)
- Prepared statements for all database queries
- Temp files created with 0600 permissions

## Dependencies

Managed via Composer:

- [aws/aws-sdk-php](https://github.com/aws/aws-sdk-php) ^3.300
- [woocommerce/action-scheduler](https://github.com/woocommerce/action-scheduler) ^3.9
