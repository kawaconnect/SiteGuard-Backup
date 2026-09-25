# SiteGuard Backup

A WordPress backup, restore, and migration plugin built for multisite networks. It handles multisite-aware backup and restore, site-to-site migration, and full WP-CLI support.

## What It Does

- **Full and incremental backups** of files and database, scheduled or on-demand
- **Per-subsite backup and restore** from Network Admin - back up or restore individual subsites without touching the rest of the network
- **Site-to-site migration** - pull backups directly from a remote WordPress site over an authenticated, encrypted connection (RSA+AES). No manual file downloads or uploads required
- **Domain remapping** - migrate between independent domains and subdomains automatically. Handles multisite blog tables, sitemeta, and serialization-aware search-replace across all subsites
- **12+ remote storage backends** - S3, Google Drive, Dropbox, OneDrive, Azure Blob, GCS, pCloud, FTP, SFTP, WebDAV, OpenStack, Email
- **WP-CLI integration** - 45+ subcommands covering backups, restores, settings, migrations, and diagnostics
- **Resumable jobs** - long-running backups resume automatically via WP-Cron if interrupted
- **Atomic database restores** - imports into temporary tables, then swaps all tables in a single `RENAME TABLE` operation
- **Split archives** - large backups split at configurable thresholds (default 400 MB)
- **Encryption** - AES-256-GCM backup encryption with PBKDF2 key derivation
- **DB anonymisation** - strip PII from database exports (all users, non-staff only, or WooCommerce orders)

## Multisite Focus

This plugin was built to manage a WordPress multisite network where subsites use independent production domains (e.g. `example.com`, `shop.otherdomain.com`) that get backed up and restored onto a standby network using mapped subdomains.

Key multisite capabilities:

- Enumerate all blog domains from `wp_blogs` during restore and build per-blog URL replacement pairs
- Handle cross-protocol replacements (HTTP/HTTPS) per subsite
- Directly fix `wp_sitemeta`, `wp_site`, and `wp_blogs` via SQL to avoid search-replace artifacts
- Preserve `site_admins` and user accounts during migration
- Detect duplicate plugin copies after a restore and deactivate the non-restored copy when both are active (nothing is deleted)
- Dispatch scheduled backup cron events for subsites that don't receive regular traffic

## Moving Sites

Every direction is supported, either by downloading a backup zip and uploading it on the other site, or by sending it directly (below):

| From | To | How to restore |
|---|---|---|
| Single site | Single site | Standard restore (migrate mode) |
| Single site | Multisite, new or existing subsite | *Import as subsite* |
| Multisite subsite | Single site | Standard restore (the subsite is converted) |
| Multisite main site | Single site | Network backup, subsite mode *main only* |
| Multisite subsite | Multisite subsite | *Import as subsite* |
| Multisite network | Multisite network | Standard restore (domains remapped) |

URLs are rewritten everywhere, including inside serialized data and escaped JSON, and the uploads path follows the site (`uploads/` <-> `uploads/sites/<id>/`). The destination keeps its own address, admin login and SiteGuard settings; the source's other users are added (matched by email, with their roles and passwords) and authorship is kept.

The health monitor API key is the one setting you choose per restore: keep this site's key (default) or use the backup's (only when the bot polls this site with the backup site's key). A site without a key takes the backup's either way. See [docs/health-api.md](docs/health-api.md#keys-backups-restores-and-failover), which also covers pinning one key on production and a standby in `wp-config.php`.

## Site-to-Site Migration

Pull backups directly from another WordPress site without manually downloading or uploading files:

1. Generate a key bundle on the **destination** site (Settings > Migration tab)
2. Paste the key bundle on the **source** site to register it as a destination
3. Send backups from source to destination - or trigger a backup-and-send in one step
4. Restore on the destination with automatic domain remapping

The connection is authenticated with RSA key pairs and encrypted with AES. Rate-limited to 60 requests/minute per IP.

## WP-CLI

```bash
# Backups
wp siteguardbackup backup [--db-only] [--files-only] [--incremental] [--label=<label>]
wp siteguardbackup list [--format=table|json]
wp siteguardbackup status
wp siteguardbackup delete <timestamp> [--delete-remote] [--yes]
wp siteguardbackup inspect <timestamp> [--format=table|json]

# Restore
wp siteguardbackup restore <timestamp> [--entities=<list>] [--db-restore-mode=<mode>] [--health-key=keep|backup]
wp siteguardbackup import <dir> --restore [--entities=<list>] [--health-key=keep|backup]
wp siteguardbackup resume_restore

# Health monitor API key (for the Discord bot); never shows a key or hash except --print-hash
wp siteguardbackup health_api_key [--format=table|json]   # fingerprint, wp-config pin, last restore
wp siteguardbackup health_api_key --regenerate
printf %s "$KEY" | wp siteguardbackup health_api_key --set   # use a key you already have (STDIN or hidden prompt)
wp siteguardbackup health_api_key --print-hash                # define() line to pin this key on a standby
wp siteguardbackup health_api_key --adopt-restored            # switch to the key the last restore did not apply

# Settings
wp siteguardbackup settings get [--key=<key>] [--format=table|json]
wp siteguardbackup settings set --<key>=<value>
wp siteguardbackup settings export [--file=<path>]
wp siteguardbackup settings import <file>

# Migration
wp siteguardbackup migrate_send <timestamp> <site_id>
wp siteguardbackup migrate_clone <site_id>
wp siteguardbackup migrate_destinations list [--format=table|json]

# Diagnostics
wp siteguardbackup sysinfo
wp siteguardbackup db_tables [--format=table|json]
wp siteguardbackup site_size [--format=table|json]
wp siteguardbackup cron_events [--sgbackup-only]
```

Run `wp help siteguardbackup` for the full command list.

## Low-Memory Hosts

The backup engine is built to run inside a small PHP memory limit (64-128 MB) on servers with 512 MB of RAM or less:

- File enumeration streams straight to an on-disk file list; the tree is never held in memory
- Database rows are fetched in byte-bounded batches and written to the gzip stream as they are produced
- Zip batches, remote-send chunks, and the PclZip fallback shrink automatically when the host is small
- `memory_limit` is raised for backup/restore runs the same way wp-admin does, capped at half the host's RAM
- Jobs resume via WP-Cron, so a small host simply takes more resumptions rather than failing

**Low Memory Mode** (Settings tab > Include in File Backups) is `auto` by default and switches on when PHP's `memory_limit` is under 128 MB or the host/container has under 1 GB of RAM. Force it with `on`, or disable with `off`:

```bash
wp siteguardbackup settings set --low_memory_mode=on
wp siteguardbackup sysinfo      # shows memory_limit, system_memory, and whether the mode is active
```

Finished backups can be downloaded as one streamed zip bundle (no temp file, constant memory) or pushed to another WordPress site in 1-2 MB encrypted chunks.

## Backup Storage Location

Backups hold the whole site and its database, so they are stored **outside the web root**. By default the plugin uses a `sgbackup` directory one level above the web root (for example `/var/www/sgbackup`, or `/home/site/sgbackup` on Azure App Service). It remembers the first directory that works, so a docroot that changes at runtime cannot move backups back into the web root.

To pin the location, add this to `wp-config.php`:

```php
define('SGBACKUP_BACKUP_DIR', '/path/outside/webroot');
```

You can also return a path from the `sgbackup_backup_dir` filter in an mu-plugin. Subsites use `site-<id>` subdirectories under the root.

If no writable directory outside the web root exists, backups fall back to `wp-content/sgbackup` and every SiteGuard admin sees an error notice. On nginx, **nothing placed in that directory protects it**: `.htaccess` is Apache-only, and nginx serves named files straight off disk. Fix the location instead of relying on stub files. Downloads from the admin always go through a capability-checked PHP endpoint.

## Safety

- Every file or directory SiteGuard deletes must resolve inside an explicit, sane root (never `/`, a system directory, or a parent of WordPress); symlinks are never followed and failures are reported, not suppressed.
- Restores that fail (wrong passphrase, SQL error) change nothing and are never mistaken for finished on retry. The optional pre-restore backup is complete or the restore does not start.
- Imported SQL cannot touch other databases on the same server or run privilege/file operations.
- On multisite, subsite administrators can only back up, download and restore their own subsite.

Tests live in `tests/` and must only run on disposable hosts; see `tests/README.md`.

## Building / Releases (GitLab CI)

The plugin is built and released by `.gitlab-ci.yml` on the GitLab runners (GitHub Actions is disabled; the old `.github/workflows/` files are kept but no longer run). Pipelines start only on a `v*` tag or when run from the GitLab UI / API, never on plain pushes or merge requests.

| Job | Runs on | What it does |
|---|---|---|
| `test` | `php:8.3-cli-bookworm` on the Linux (Kubernetes) runner | `ci/test.sh`: `php -l` on every PHP file outside `vendor/`, then `tests/test-*.php` (delete guard, encryption) as `nobody`, since those tests refuse root |
| `build-zip` | `alpine:3.22` | `ci/build-zip.sh`: builds `siteguardbackup-<version>.zip` (one `siteguardbackup/` folder, without `docs/`, `tests/`, git and CI files), kept 30 days as a job artifact |
| `release` | `alpine:3.22`, tags / `RELEASE_VERSION` only | `ci/release.sh`: uploads the zip to the project's Generic Package Registry (`siteguardbackup/<version>`) and creates or updates the GitLab release for the tag, with this version's `CHANGELOG.md` section as the notes |

To cut a release:

1. Bump the version in both places in `siteguardbackup.php` (the `Version:` header and `SGBACKUP_VERSION`) and add a `## <version>` section to `CHANGELOG.md`.
2. Tag the commit `v<version>` (e.g. `v1.5.1`) and push the tag (without `-o ci.skip`, which would skip the release pipeline). The build fails if the tag, the header, `SGBACKUP_VERSION` and the CHANGELOG section do not all match.
3. To publish again for an existing tag, run a pipeline on that tag from the UI with `RELEASE_VERSION=v<version>`. Re-running updates the release notes and the zip link in place; nothing is removed.

Differences from the old GitHub workflow: pushes to `master` no longer publish an automatic `v<version>-<sha7>` release (releases come only from `v*` tags), and the release is titled `SiteGuard Backup <version>` rather than the commit subject. Zip entries are normalised to 0644 files / 0755 folders whatever the checkout's umask, and the build fails if any entry is group- or world-writable.

Only the unit tests run in CI, on PHP 8.3 (as the GitHub workflow did); the plugin itself supports PHP 7.4+. The integration suites in `tests/integration/` need disposable Docker WordPress instances and are run by hand; see `tests/README.md`. Because the unit tests call real delete functions, run them locally with `sudo tests/run-sandboxed.sh` (read-only `/`, private `/tmp`, tests as `nobody`). `ci/test.sh` is the CI entry point and has no sandbox of its own; use it only in CI or a throwaway container. Build the zip locally with `ci/build-zip.sh [version] [out-dir]`.

## Requirements

- WordPress 5.6+
- PHP 7.4+

## License

GPLv2 or later. See [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).

Portions copyright UpdraftPlus.Com and David Anderson, GPLv2.
