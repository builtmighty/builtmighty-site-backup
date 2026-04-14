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
- **Retention management** — automatically prunes old backups beyond a configurable limit
- **Backup history** — logs every backup with status, sizes, and errors
- **Email notifications** — alerts on backup failure
- **Dev Mode detection** — prevents dev/staging sites from overwriting production backups
- **Codespace integration** — REST API endpoint and bootstrap key for the pipeline
- **Devcontainer management** — check and update .devcontainer config via GitHub API with automatic Codespace tier sizing (with 20% headroom) based on site disk usage; creates resize PRs when a site outgrows its current tier; target branch is selectable from a dropdown of the repo's branches (defaults to the repo's default branch)
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
2. Run `composer install` from the plugin directory.
3. Activate the plugin in WordPress (or Network Activate on multisite).
4. Go to **MightyBackup** in the admin menu and configure your DigitalOcean Spaces credentials.

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
- `wp-content/updraft` (UpdraftPlus)
- `wp-content/ai1wm-backups` (All-in-One WP Migration)
- `wp-content/backups-dup-lite` (Duplicator)
- `wp-content/backups-dup-pro` (Duplicator Pro)
- `wp-content/object-cache.php` (production drop-in)
- `wp-content/advanced-cache.php` (production drop-in)

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
```

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
`github_repo`, `github_pat`.

### Devcontainer

Manage the repo's `.devcontainer` configuration via the GitHub API — the CLI
equivalent of the Devcontainer tab in the admin UI.

```bash
# Check current vs. latest version and list available branches
wp mighty-backup devcontainer check [--format=<table|json|yaml>]

# Create a PR to install or update .devcontainer
wp mighty-backup devcontainer update [--branch=<branch>] [--yes]
```

If `--branch` is omitted, the PR targets the repository's default branch.
`--yes` skips the confirmation prompt (useful for automation).

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

Returns encrypted credentials and backup configuration for the Codespace bootstrap pipeline. HTTPS only, rate-limited to 10 requests per 60 seconds per IP.

A **Bootstrap Key** (available on the settings page) combines the site URL and API key into a single Base64-encoded secret for Codespace setup.

## How It Works

Backups are executed as a chain of background steps via Action Scheduler:

1. **Start** — initialize backup, create log entry
2. **Export Database** — stream a gzipped SQL dump using primary-key pagination (binary columns exported as hex). When mysqldump is unavailable, the PHP export is automatically chunked across multiple Action Scheduler actions to avoid timeout and memory limits on large databases.
3. **Archive Files** — create a `tar.gz` archive (shell `tar` preferred, streaming PHP fallback); symlinked plugins are dereferenced and included. On hosts where `WP_CONTENT_DIR` is outside `ABSPATH` (e.g., Pressable), both locations are archived automatically. Files that change during archival (caches, sessions, logs) are handled gracefully — tar exit code 1 is logged as a non-fatal warning.
4. **Upload Database** — multipart upload to Spaces (25 MB parts, 5 concurrent)
5. **Upload Files** — multipart upload to Spaces
6. **Cleanup** — run retention policy, delete temp files, mark complete

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
