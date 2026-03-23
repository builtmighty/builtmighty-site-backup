# Built Mighty Site Backup

A WordPress plugin that automates site backups to DigitalOcean Spaces. Designed for the Built Mighty team, it supports scheduled and on-demand backups of both the database and file system, with built-in integration for the staged-loader Codespace pipeline.

[![Download ZIP](https://img.shields.io/badge/Download-ZIP-blue?style=for-the-badge)](https://github.com/builtmighty/builtmighty-site-backup/releases/latest/download/builtmighty-site-backup.zip)

## Install via WP-CLI

```bash
wp plugin install https://github.com/builtmighty/builtmighty-site-backup/releases/latest/download/builtmighty-site-backup.zip --activate
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
- **Devcontainer management** — check and update .devcontainer config via GitHub API
- **WP-CLI support** — full command-line interface with timeout control
- **Pressable & managed hosting compatible** — follows symlinked plugins, secure mysqldump via defaults file
- **Multisite compatible** — settings stored at the network level
- **Cancel in-progress backups** — stop a running backup from the admin UI or WP-CLI
- **Developer filters** — tune batch size, part size, concurrency, and gzip levels via `add_filter()`
- **Action hooks** — fire custom code before/after each backup step, on completion, or on failure
- **Access-controlled settings** — settings page restricted to authorized `@builtmighty.com` accounts

## Installation

1. Upload the plugin to `wp-content/plugins/builtmighty-site-backup`.
2. Run `composer install` from the plugin directory.
3. Activate the plugin in WordPress (or Network Activate on multisite).
4. Go to **Settings > Site Backup** and configure your DigitalOcean Spaces credentials.

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

## WP-CLI Commands

```bash
# Run a backup (synchronous by default)
wp bm-backup run [--type=<full|db|files>] [--async] [--timeout=<seconds>]

# Check backup status
wp bm-backup status

# List backups stored on Spaces
wp bm-backup list [--type=<all|db|files>]

# Manually trigger retention cleanup
wp bm-backup prune

# Test the Spaces connection
wp bm-backup test
```

## Developer Hooks & Filters

### Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `bm_backup_db_batch_size` | `1000` | Rows per paginated DB export query |
| `bm_backup_db_gzip_level` | `3` | Gzip compression level for DB dump (1–9) |
| `bm_backup_files_gzip_level` | `3` | Gzip compression level for file archive (1–9) |
| `bm_backup_upload_part_size` | `26214400` | Multipart upload part size in bytes (25 MB) |
| `bm_backup_upload_concurrency` | `5` | Concurrent upload parts |
| `bm_backup_upload_max_retries` | `3` | Max upload retries per part |
| `bm_backup_admin_domains` | `['builtmighty.com']` | Email domains permitted to access the settings page |
| `bm_backup_streamlined_days` | `90` | Days of WooCommerce orders to include in streamlined mode |
| `bm_backup_is_log_table` | `(bool)` | Override whether a table is treated as a log table in streamlined mode |
| `bm_backup_order_table_config` | `(array)` | Override the order table → ID column mapping in streamlined mode |

### Action Hooks

| Hook | Args | Description |
|------|------|-------------|
| `bm_backup_before_start` | `$state` | Fires at the top of the start step |
| `bm_backup_after_start` | `$state` | Fires after the start step |
| `bm_backup_before_export_db` | `$state` | Fires before DB export |
| `bm_backup_after_export_db` | `$state, $db_path` | Fires after DB export |
| `bm_backup_before_archive_files` | `$state` | Fires before file archive |
| `bm_backup_after_archive_files` | `$state, $files_path` | Fires after file archive |
| `bm_backup_before_upload` | `$state, $type` | Fires before each upload step |
| `bm_backup_after_upload` | `$state, $type, $remote_key` | Fires after each upload step |
| `bm_backup_completed` | `$state` | Fires when backup completes successfully |
| `bm_backup_failed` | `$state, $error` | Fires when backup fails |

## REST API

### Codespace Config

```
GET /wp-json/bm-backup/v1/codespace-config
Authorization: Bearer <api-key>
```

Returns encrypted credentials and backup configuration for the Codespace bootstrap pipeline. HTTPS only, rate-limited to 10 requests per 60 seconds per IP.

A **Bootstrap Key** (available on the settings page) combines the site URL and API key into a single Base64-encoded secret for Codespace setup.

## How It Works

Backups are executed as a chain of background steps via Action Scheduler:

1. **Start** — initialize backup, create log entry
2. **Export Database** — stream a gzipped SQL dump using primary-key pagination (binary columns exported as hex)
3. **Archive Files** — create a `tar.gz` archive (shell `tar` preferred, streaming PHP fallback); symlinked plugins are dereferenced and included
4. **Upload Database** — multipart upload to Spaces (25 MB parts, 5 concurrent)
5. **Upload Files** — multipart upload to Spaces
6. **Cleanup** — run retention policy, delete temp files, mark complete

Each step runs independently to avoid timeout issues on resource-constrained hosts.

## Security

- Secret keys encrypted with AES-256-CBC using WordPress salts
- Optional `BM_BACKUP_SECRET` constant in `wp-config.php` adds a second pepper to AES-256-CBC key derivation
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
