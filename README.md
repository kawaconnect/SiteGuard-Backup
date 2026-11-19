# SiteGuard Backup

A WordPress backup, restore, and migration plugin built for multisite networks. It handles multisite-aware backup and restore, site-to-site migration, and full WP-CLI support.

## What It Does

- **Full and incremental backups** of files and database, scheduled or on-demand
- **Per-subsite backup and restore** from Network Admin - back up or restore individual subsites without touching the rest of the network
- **Site-to-site migration** - send backups directly from one WordPress site to another over an authenticated, encrypted connection (RSA+AES)
- **Domain remapping** - migrate between independent domains and subdomains automatically. Handles multisite blog tables, sitemeta, and serialization-aware search-replace across all subsites
- **14 remote storage destinations** (plus direct send to another SiteGuard site): Amazon S3 and S3-compatible services, Backblaze B2, Google Cloud Storage, Azure Blob, Google Drive, Dropbox, OneDrive, pCloud, FTP/FTPS, SFTP/SCP, WebDAV, OpenStack Swift, Email (see [Remote storage](#remote-storage))
- **WP-CLI integration** - 55 subcommands covering backups, restores, settings, migrations, and diagnostics
- **Resumable jobs** - long-running backups resume automatically via WP-Cron if interrupted
- **Atomic database restores** - imports into temporary tables, then swaps all tables in a single `RENAME TABLE` operation
- **Split archives** - large backups split at configurable thresholds (default 400 MB)
- **Encryption** - AES-256-GCM backup encryption with PBKDF2 key derivation
- **DB anonymisation** - strip PII from database exports (all users, non-staff only, or WooCommerce orders)

## Install

1. Download `siteguardbackup-<version>.zip` from the releases page (or build it with `ci/build-zip.sh`).
2. In WordPress: Plugins > Add New > Upload Plugin, choose the zip, activate. On multisite, network-activate it.
3. Open **SiteGuard Backup** in the admin menu. It needs `manage_options` (on multisite: `manage_network_options` for the network and main site; a subsite administrator sees only their own subsite's backups).
4. Check the admin notices: if backups would land inside the web root, fix that first (see [Backup Storage Location](#backup-storage-location)).

Nothing is scheduled and nothing leaves the server until you configure it: schedules default to manual, two backups of each kind are kept, and no remote storage is selected.

## Quick Start

- **Back up now:** Backup / Restore tab > Backup Now, or `wp siteguardbackup backup`.
- **Schedule:** Settings tab, or `wp siteguardbackup schedule set --files=daily --database=daily --retain=7 --retain-db=7`. WP-Cron only runs when the site gets traffic; on quiet sites call `wp cron event run --due-now` from system cron.
- **Remote storage:** Settings tab > pick a destination, save its credentials, press Test Connection. See [Remote storage](#remote-storage).
- **Encrypt archives:** Settings tab > Encryption, set a passphrase. Write the passphrase down: it is not recoverable and a backup cannot be restored without it.
- **Restore:** Backup / Restore tab > Restore on a backup row, choose what to restore, confirm. Or `wp siteguardbackup restore <timestamp>`. Uploaded archives (the upload box, or files copied into the backup directory followed by Rescan Local) restore the same way.

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

On a docker node built with WPMultisiteDocker, the node's agent tracks every site it hosts (one install each) with a node key it sets itself next to each site's own key; the bot then needs only the node's token. See [docs/health-api.md](docs/health-api.md#sites-hosted-on-a-docker-node-node-key).

The health monitor API key is the one setting you choose per restore: keep this site's key (default) or use the backup's (only when the bot polls this site with the backup site's key). A site without a key takes the backup's either way. See [docs/health-api.md](docs/health-api.md#keys-backups-restores-and-failover), which also covers pinning one key on production and a standby in `wp-config.php`.

## Site-to-Site Migration

Send backups directly to another WordPress site without downloading and uploading files by hand. Both sites run SiteGuard Backup:

1. Generate a key bundle on the **destination** site (SiteGuard Backup > Migrate / Clone tab)
2. Paste the key bundle on the **source** site to register it as a destination
3. Send backups from source to destination - or trigger a backup-and-send in one step
4. Restore on the destination with automatic domain remapping

The connection is authenticated with RSA key pairs and encrypted with AES. The receiving site accepts at most 600 requests per minute per IP. Keys can be given an expiry date; keys made before 1.7.0 are refused, create a new one.

## WP-CLI

```bash
# Backups
wp siteguardbackup backup [--exclude-db] [--include-files=plugins,themes,uploads,others,wpcore] [--send-to-cloud]
                          [--incremental] [--always-keep] [--label=<label>]   # local only unless --send-to-cloud
wp siteguardbackup list [--format=table|json]
wp siteguardbackup status
wp siteguardbackup delete <timestamp> [--delete-remote] [--yes]
wp siteguardbackup inspect <timestamp> [--format=text|json]

# Restore
wp siteguardbackup restore <timestamp> [--entities=<list>] [--db-restore-mode=<mode>] [--health-key=keep|backup]
wp siteguardbackup import <dir> --restore [--entities=<list>] [--health-key=keep|backup]
wp siteguardbackup resume_restore

# Health monitor API key (for an external monitor). The status output never shows a key or hash;
# --regenerate prints the new key once, --print-hash prints the hash.
wp siteguardbackup health_api_key [--format=table|json]   # fingerprint, wp-config pin, last restore
wp siteguardbackup health_api_key --regenerate
printf %s "$KEY" | wp siteguardbackup health_api_key --set   # use a key you already have (STDIN or hidden prompt)
wp siteguardbackup health_api_key --print-hash                # define() line to pin this key on a standby
                                                              # (in docker-compose/.env write each $ as $$; see docs/health-api.md)
wp siteguardbackup health_api_key --adopt-restored            # switch to the key the last restore did not apply
printf %s "$KEY" | wp siteguardbackup health_api_key --set-node   # node key for the hosting docker node's agent (site key untouched)
wp siteguardbackup health_api_key --clear-node

# Settings
wp siteguardbackup settings get [--key=<key>] [--format=table|json]
wp siteguardbackup settings set --<key>=<value>
wp siteguardbackup settings export [--file=<path>] [--include-secrets]
wp siteguardbackup settings import <file>

# Migration
wp siteguardbackup migrate_send <timestamp> <site_id>
wp siteguardbackup migrate_clone <site_id>
wp siteguardbackup migrate_destinations list [--format=table|json]

# Sync API (pull backups from this site; used by the wpops node agent)
wp siteguardbackup sync_api_key [--regenerate|--delete]

# Diagnostics
wp siteguardbackup sysinfo
wp siteguardbackup db_tables [--format=table|json]
wp siteguardbackup site_size [--format=table|json]
wp siteguardbackup cron_events [--sgbackup-only]
```

Run `wp help siteguardbackup` for the full command list.

## Uptime Kuma

The health monitor (HTTP and SSL checks of every site, Health Monitor tab) can send one heartbeat per run to an Uptime Kuma **Push** monitor: `up`, or `down` naming the failing sites (`2/12 failing; down: example.com (HTTP 503); ssl_expired: example.org`). When WordPress, PHP, the database or WP-Cron stops, the heartbeats stop and Kuma alerts too. Set the push URL on the Health Monitor tab or with `wp siteguardbackup health_kuma --push-url=<push url>` (`--test`, `--clear`); set the Kuma heartbeat interval to at least two health check intervals (1320 s for the default 10 minutes). Monitor settings, message format and cron notes: [docs/uptime-kuma.md](docs/uptime-kuma.md).

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

Finished backups can be downloaded as one streamed zip bundle (no temp file, constant memory; sets over 4 GB are downloaded file by file) or pushed to another WordPress site in 1-2 MB encrypted chunks.

## Remote storage

Every destination is exercised by `tests/integration/storage.sh` (upload,
multipart/chunked upload, listing, byte-identical download, delete) against a
real server or a protocol fake written from the provider's API docs.

| Destination | Needs | Notes |
|---|---|---|
| Amazon S3, S3-compatible, B2, GCS | access key + secret | SigV4; B2 and GCS use their S3-compatible APIs (GCS: HMAC keys) |
| Azure Blob | account + key or SAS | block blobs |
| Google Drive, Dropbox, OneDrive, pCloud | your own OAuth app | sign in from the settings page (below) |
| FTP / FTPS | PHP curl (or ext-ftp for plain FTP) | explicit and implicit FTPS; the server certificate is verified |
| SFTP / SCP | password, or a pasted RSA private key | bundled phpseclib; Ed25519/ECDSA keys are not supported |
| WebDAV | URL, user, password | Nextcloud, ownCloud, NAS |
| OpenStack Swift | Keystone v2.0 or v3 credentials | large files as static large objects |
| Email | an address | upload only; archives over 25 MB are not sent |

### OAuth sign-in

Create an app with the provider, register this redirect URI (shown in each
OAuth panel), save the app credentials, then click "Sign in with ...":

    https://<your site>/wp-json/sgbackup/v1/oauth/callback

- Google: OAuth client of type "Web application", Google Drive API enabled.
  Scope `drive.file` (SiteGuard only sees files it created).
- Dropbox: app with `files.content.read` and `files.content.write`.
- Microsoft: app registration, platform "Web", delegated permission
  `Files.ReadWrite`. Use tenant `common` for apps that allow personal and work
  accounts, or the tenant ID for a single-organization app.
- pCloud: app at docs.pcloud.com > My Apps. The data region is detected.

The redirect URI must not contain a query string (Microsoft rejects it for
personal accounts), so the site needs pretty permalinks.

## Backup Storage Location

Backups hold the whole site and its database, so they are stored **outside the web root**. By default the plugin uses a `sgbackup` directory one level above the web root (for example `/var/www/sgbackup`, or `/home/site/sgbackup` on Azure App Service). It remembers the first directory that works, so a docroot that changes at runtime cannot move backups back into the web root.

To pin the location, add this to `wp-config.php`:

```php
define('SGBACKUP_BACKUP_DIR', '/path/outside/webroot');
```

You can also return a path from the `sgbackup_backup_dir` filter in an mu-plugin. Subsites use `site-<id>` subdirectories under the root.

If no writable directory outside the web root exists, backups fall back to `wp-content/sgbackup`. On Apache and LiteSpeed that directory gets deny rules (`.htaccess` and friends). On any other server every SiteGuard admin sees an error notice, because on nginx **nothing placed in that directory protects it**: `.htaccess` is Apache-only, and nginx serves named files straight off disk. Fix the location instead of relying on stub files. Downloads from the admin always go through a capability-checked PHP endpoint.

## Security Notes

- **Backup archives are not encrypted unless you set a passphrase.** With one, archives are AES-256-GCM (`.crypt`), key derived with PBKDF2-SHA256. The passphrase is stored in the WordPress database so scheduled backups can use it.
- **Secrets are encrypted at rest by default** (since 1.7.1): storage credentials, the backup passphrase and the webhook signing secret are stored AES-256-GCM encrypted with a key derived from `AUTH_KEY` in `wp-config.php`. Updating from an older version encrypts the ones stored in plaintext (wp-admin, wp-cron or WP-CLI runs it once; values are only replaced after they decrypt back). The setting is under Advanced Tools ("Encrypt storage credentials at rest") and can be turned off.
- **If `AUTH_KEY` changes or is removed** (new host, regenerated salts, a database copied to another site) the stored secrets cannot be decrypted. SiteGuard notices it, shows an admin notice naming the affected secrets and keeps the ciphertext: putting the old `AUTH_KEY` back makes them readable again, otherwise enter them again. Until then remote uploads with those credentials fail, encrypted backups are refused and webhooks are not sent. Without `AUTH_KEY`, secrets stay in plaintext and a notice says so.
- **Settings exports leave secrets out** (storage credentials, passphrase, webhook URL and secret, external database passwords; the settings-lock hash is never exported). Importing such a file keeps this site's own secrets. To move settings with their secrets to another site, tick "Include passwords and keys" (or `settings export --include-secrets`): the file then holds them in plaintext, so treat it like a password store and delete it after the import.
- The webhook URL (it often carries a token) and the signing secret are not shown on the settings page; Show and Regenerate fetch the secret on request.
- Secrets already saved are never sent back to the settings page; fields show a placeholder and keep the stored value unless you type a new one.
- The health and sync API keys are stored hashed. `--regenerate` shows a key once; it cannot be read back later.
- Optional hardening: an IP allowlist for the admin screens (Advanced Tools tab), a settings lock password (Settings tab), and an audit log (`wp siteguardbackup audit_log`).
- Site health probes and the post-restore smoke test do not verify TLS certificates (they only check that the site answers and read the certificate's expiry). Storage and webhook requests verify certificates unless you switch that off under Settings > Expert / Advanced Settings.

## Safety

- Every file or directory SiteGuard deletes must resolve inside an explicit, sane root (never `/`, a system directory, or a parent of WordPress); symlinks are never followed. This covers retention, restore, temp cleanup and uninstall.
- Restores that fail (wrong passphrase, SQL error) change nothing and are never mistaken for finished on retry. The optional pre-restore backup is complete or the restore does not start.
- Imported SQL cannot touch other databases on the same server or run privilege/file operations.
- On multisite, subsite administrators can only back up, download and restore their own subsite.
- The client IP (IP allowlist, rate limits, audit log) is `REMOTE_ADDR`; `CF-Connecting-IP` is used only when the connection comes from Cloudflare or loopback. `X-Forwarded-For` is never used. Behind a local proxy or tunnel in another container (cloudflared, nginx), for example a standby in Docker, list that proxy in `wp-config.php`, or all visitors share its IP: `define('SGBACKUP_TRUSTED_PROXIES', '172.19.0.0/16');` (comma-separated IPs or CIDRs). See [docs/health-api.md](docs/health-api.md#authentication).

Tests live in `tests/` and must only run on disposable hosts; see `tests/README.md`.

## Configuration Reference

`wp-config.php` constants (all optional):

| Constant | Purpose |
|---|---|
| `SGBACKUP_BACKUP_DIR` | Absolute path of the backup directory, outside the web root |
| `SGBACKUP_TRUSTED_PROXIES` | Comma-separated IPs/CIDRs of reverse proxies whose `CF-Connecting-IP` header is trusted |
| `SGBACKUP_HEALTH_ENABLED` | Pin the health monitor on or off regardless of the saved setting |
| `SGBACKUP_HEALTH_API_KEY_HASH` | Pin a health API key by its hash (see [docs/health-api.md](docs/health-api.md)) |
| `SGBACKUP_SYNC_API_KEY_HASH` | Pin a sync API key by its hash: `password_hash($key, PASSWORD_BCRYPT)` |

Filters: `sgbackup_backup_dir`, `sgbackup_trusted_proxy_cidrs`, `sgbackup_oauth_redirect_uri`, `sgbackup_email_max_attachment_bytes` (default 25 MB), `sgbackup_low_memory`, `sgbackup_memory_limit`, `sgbackup_s3_part_size`, `sgbackup_stale_job_after` (default 6 hours).

### Sync API

Lets a trusted puller start a backup here and download the result. Off until a key exists (`wp siteguardbackup sync_api_key --regenerate`); every request needs `Authorization: Bearer <key>`. On multisite only the main site answers. Routes under `/wp-json/sgbackup/v1/sync`:

| Route | Does |
|---|---|
| `GET /ping` | Checks the key; returns version and running jobs |
| `POST /backup` | Starts a local backup (`label`, `entities[]`, `db=false`); returns the job `nonce`; `409` while another job runs |
| `GET /jobs/<nonce>` | Job status |
| `GET /sets[?hash=1]` | Backup sets and their files (sizes; SHA-256 with `hash=1`) |
| `GET /sets/<nonce>/file?name=<file>` | Downloads one file; HTTP `Range` is supported |
| `DELETE /sets/<nonce>` | Deletes a set (`409` if it is marked always-keep) |

Anyone holding this key can download full backups of the site. Failed attempts are rate-limited per IP together with the health API.

## Troubleshooting

- **A backup seems stuck:** `wp siteguardbackup backup_progress` shows running jobs and their job id (nonce); `wp siteguardbackup get_log <nonce> --tail=50` shows the log. `wp siteguardbackup force_resume <nonce>` continues a job whose WP-Cron event was lost; `wp siteguardbackup abort <nonce>` stops it. Jobs that make no progress for 6 hours are aborted automatically.
- **"A backup job is already running":** wait for it, or stop it with the Abort button in the job list or with `abort`.
- **Scheduled backups do not run:** `wp siteguardbackup cron_events --sgbackup-only` shows what is queued. WP-Cron needs traffic or a system cron job.
- **Storage upload fails:** use the Test button on the destination; `wp siteguardbackup sysinfo` lists PHP extensions and limits. SFTP accepts RSA keys only.
- **"SiteGuard Backup cannot read its stored secrets":** `AUTH_KEY` changed (or is missing) since they were saved. Restore the previous `AUTH_KEY`, or enter the listed secrets again; the notice goes away once every stored secret decrypts.
- **Restore stops halfway:** reopen the page and use the resume prompt, or `wp siteguardbackup resume_restore`. A failed database import leaves the live tables untouched.
- **Locked out by the IP allowlist or the settings lock:** `wp siteguardbackup settings set --ip_allowlist= --lock_admin=0`.
- Turn on debug mode (Settings tab > Debug) for more detail in job logs.

## Upgrading

Read the `CHANGELOG.md` section of the version you install. Coming from before 1.7.0: migration keys must be recreated on the destination and added again on the source; the OAuth redirect URI changed and must be registered again with Google, Dropbox, Microsoft or pCloud; on multisite the main site's page is for network administrators only and a subsite's own backups hold its database and uploads only.

## Building / Releases (GitLab CI)

The plugin is built and released by `.gitlab-ci.yml` on the GitLab runners (the GitHub repository is a plain mirror with no workflows). Pipelines start on a `v*` tag, when run from the GitLab UI / API, and on merge requests (test and build-zip only, nothing is published); never on plain pushes.

| Job | Runs on | What it does |
|---|---|---|
| `test` | `php:8.3-cli-bookworm` on the Linux (Kubernetes) runner | `ci/test.sh`: `php -l` on every PHP file outside `vendor/`, then every `tests/test-*.php` as `nobody`, since those tests refuse root |
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
