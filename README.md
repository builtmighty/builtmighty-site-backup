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
- **Retention management** — automatically prunes old backups beyond a configurable limit
- **Backup history** — logs every backup with status, sizes, and errors
- **Email notifications** — alerts on backup failure
- **Codespace integration** — REST API endpoint for the bootstrap pipeline
- **WP-CLI support** — full command-line interface
- **Multisite compatible** — settings stored at the network level

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
wp bm-backup run [--type=<full|db|files>] [--async]

# Check backup status
wp bm-backup status

# List backups stored on Spaces
wp bm-backup list [--type=<all|db|files>]

# Manually trigger retention cleanup
wp bm-backup prune

# Test the Spaces connection
wp bm-backup test
```

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
2. **Export Database** — stream a gzipped SQL dump using primary-key pagination
3. **Archive Files** — create a `tar.gz` archive (shell `tar` preferred, PharData fallback)
4. **Upload Database** — multipart upload to Spaces (10 MB parts, 5 concurrent)
5. **Upload Files** — multipart upload to Spaces
6. **Cleanup** — run retention policy, delete temp files, mark complete

Each step runs independently to avoid timeout issues on resource-constrained hosts.

## Security

- Secret keys encrypted with AES-256-CBC using WordPress salts
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
