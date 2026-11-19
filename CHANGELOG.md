# Changelog

## 1.7.1

### Security
- Secrets are encrypted at rest by default. Storage method credentials, the
  backup encryption passphrase and the webhook signing secret are stored
  AES-256-GCM encrypted with a key derived from `AUTH_KEY`. Before, storage
  credentials were only encrypted after turning on "Encrypt storage
  credentials at rest", and the passphrase and webhook secret were always
  stored as typed. The setting can still be turned off under Advanced Tools.
- Updating encrypts what older versions stored in plaintext, once per site
  (on the next wp-admin page, wp-cron run or WP-CLI command). A value is only
  replaced by ciphertext that decrypted back to it. Without `AUTH_KEY` nothing
  changes and it is tried again later. Plaintext values keep working.
- A changed or missing `AUTH_KEY` no longer loses secrets silently. SiteGuard
  stores a fingerprint of the key with the secrets, notices the change, and
  shows an admin notice listing what cannot be decrypted and what to do. The
  stored ciphertext is kept: a save that does not re-enter a credential, a
  token refresh or the SFTP host key save no longer overwrite it with an
  empty value, so restoring the old `AUTH_KEY` brings everything back. An
  unreadable webhook secret is no longer replaced by a new one (webhooks are
  held back instead), and an unreadable passphrase fails the backup with that
  reason. Without `AUTH_KEY`, an encrypted value was handed to the provider
  as the credential; it now reads as empty and is reported.
- Settings exports leave out passwords and keys (storage credentials,
  passphrase, webhook URL and secret, external database passwords; the settings-lock
  hash is never exported). "Include passwords and keys" (WP-CLI:
  `settings export --include-secrets`) adds them, decrypted so another site
  can read them, after a warning; the CLI writes that file mode 0600.
  Importing a file without secrets keeps this site's own; encrypted values
  from another site's export are skipped and reported instead of stored.
- The settings page no longer prints the webhook URL (it often carries a
  token) or the signing secret. The URL shows only its host; the secret is
  fetched with Show (logged in the audit log) or replaced with Regenerate.

### Fixed
- mysqldump option file: user, password and host are quoted, so a password
  with `#` (a comment outside quotes), quotes, backslashes or surrounding
  spaces reaches the server intact. A password with both quote characters is
  passed in mysqldump's environment instead.
- Admin bar "Backup Now" only opened the page; it now opens the Backup Now
  dialog. The dialog's confirm button said "Backup started" before anything
  had started.
- "Rescan Local" and "Rescan Remote" stopped responding once the backup list
  had refreshed after a backup. They are also shown when there are no backups
  yet.
- "Upload to remote storage" showed a failed destination in green. Bulk delete
  dropped failures without a message.
- Request failures said only "An error occurred"; they now say whether the
  session expired, the server timed out, returned an error or was unreachable.

### Changed
- Aborting a backup job asks for confirmation. The delete, bulk delete and
  reset-settings confirmations say what is removed (reset also removes storage
  credentials, the encryption passphrase and the lock password; delete leaves
  remote copies in place).
- Health Monitor table links are only rendered for http(s) URLs.
- Removed `js/restore.js` (unused) and two finished planning documents from
  `docs/`.
- README: install, quick start, security notes, configuration reference, sync
  API routes, troubleshooting and upgrade notes; corrected WP-CLI flags and the
  migration rate limit.

### Removed
- `.github/workflows`: GitLab CI is the only CI; the GitHub repository is a
  plain mirror.

## 1.7.0

Every storage destination was tested end to end against a real server or a
protocol fake written from the provider's API documentation
(tests/integration/storage.sh), and a backup job was run to several
destinations at once. What did not work is fixed below.

### Security
A full audit of the plugin (unauthenticated endpoints, admin screens, restore,
backup engine, storage modules) found the following. The first four let a
**subsite administrator on a multisite network** reach beyond their own site;
single-site installs were not affected by them.

- **A subsite administrator could download `wp-config.php` and any readable
  directory.** "Backup Now" accepted any file entity (WordPress core, which
  includes `wp-config.php` for local backups) and "Extra directories" accepted
  any server path; the resulting set was theirs to download. A subsite's own
  backups (scheduled, or started by its administrator) now hold its database
  and uploads only. Extra directories, extra databases, non-WordPress tables,
  the entity list, the webhook and the failover and health settings can only be
  changed by a network administrator. A set a network administrator makes on a
  subsite (which may include shared code) is marked and is not downloadable or
  sendable by the subsite's administrator.
- **A subsite administrator could run SQL against the whole network** by
  uploading a backup and importing it into their own site. The import filter
  only recognised backticked `DROP`/`CREATE`/`INSERT INTO` statements and ran
  everything else as written (`INSERT INTO wp_sitemeta ...`, `INSERT ... SELECT
  ... FROM wp_users`, multi-table `DROP TABLE`, `UPDATE`, `RENAME`). Subsite
  imports now run a strict allowlist: a single-table `DROP TABLE`, `CREATE
  TABLE` without `SELECT` or file paths, and `INSERT INTO ... VALUES` whose
  rows are literals, each on a table under the target site's prefix. Everything
  else is refused (tests/test-sql-import-filter.php).
- **A subsite administrator could plant PHP in the uploads directory** through
  an uploads restore. Executable and server-config files (`.php`, `.phtml`,
  `.phar`, double extensions such as `x.php.jpg`, `.htaccess`, `.user.ini`,
  ...) are left out of an uploads import unless a network administrator or
  WP-CLI runs it.
- **A main-site administrator who is not a network administrator** could send
  the network-wide backups to their own storage or delete them. The main site's
  SiteGuard page and commands are now for network administrators only.
- A subsite no longer inherits settings stored at network level by old
  versions (storage credentials, encryption passphrase, lock hash).
- A subsite administrator can no longer use "Test webhook", the migration
  destination ping or the health check to make the server request internal
  addresses or probe every site of the network.
- Every restore path: `INSERT`/`REPLACE` statements must hold literal rows (no
  subqueries, no `LOAD_FILE()`, no `ON DUPLICATE KEY UPDATE`), `CREATE TABLE
  ... SELECT` and `DATA DIRECTORY` are refused, and a `DROP`/`RENAME TABLE`
  naming a table in another database is refused wherever it appears in the
  statement. Data unserialized from a backup during restore never instantiates
  objects.
- **Encryption failed open.** With encryption enabled but no passphrase set,
  or when encrypting a file failed, the plaintext archive was uploaded to
  remote storage and the job reported as a success. The job now fails before
  the upload phase.
- OAuth tokens (Google Drive, Dropbox, OneDrive) were stored in plaintext even
  with "encrypt credentials" on. They are encrypted now (on the next token
  refresh or settings save).
- Turning "encrypt credentials" off left the stored ciphertext in place and
  sent it to the storage provider as the secret. Encrypted values are always
  decrypted when read.
- The settings page showed the first and last four characters of the
  encryption passphrase, and `get_settings` returned external database
  passwords. Neither is shown now.
- The decryption passphrase typed for a restore was kept in the restore
  progress record in the database for 24 hours.
- OneDrive downloads no longer send the Graph access token to the host the
  download is redirected to.
- S3-compatible and B2 custom endpoints: `HTTP://host` (upper case) was left as
  cleartext; a host without a scheme now gets `https://`.
- The unlock form now honours the IP allowlist.
- The file-list cache written during a backup no longer has a `.php`
  extension.

- **Site-to-site migration keys authenticate every message.** A key used to
  be its RSA public key: anyone holding the bundle (it sat in plaintext in the
  sending site's database and therefore in its backups) could write archives
  into the receiving site and edit received sets, on every subsite of a
  network, forever. Now:
  - each key has a random 256-bit secret, carried in the bundle and stored
    encrypted on both sites; every request carries an HMAC made with it, checked
    before any decryption, and every answer is signed with it and bound to the
    request, so a forged "already uploaded" reply is detected;
  - a key belongs to the site it was created on, can expire (the Migrate tab
    offers 1, 7, 30 or 365 days, or never for scheduled sends;
    `wp siteguardbackup create_migration_key --expires=<days>`), and each
    message is accepted once (atomic nonce record);
  - a received set can only be changed by the key that sent it;
  - keys and bundles made before 1.7.0 are refused with a message saying to
    create a new key (the network option `sgbackup_allow_legacy_migration_keys`
    re-enables them).
- The subsite import SQL filter could be bypassed by a versioned comment
  (`/*! ... */`) or by mixing quote types in a `CREATE TABLE`; the statement is
  now read in one pass the way MySQL reads it, and table definitions in a
  subsite import may not contain comments.
- A second review of the import filter closed more ways around it: `--`
  followed by a control character (MySQL treats it as a comment), an
  unterminated quote, MariaDB `/*M! */` executable comments, and `SET sql_mode`
  values (`NO_BACKSLASH_ESCAPES`, `ANSI_QUOTES`) that make MySQL read quotes
  differently. Subsite imports accept only the session settings a dump needs.
  Versioned `PARTITION` clauses in table definitions are accepted again.
- Staged subsite imports keep foreign keys working (WooCommerce's named
  constraints collided with the live table's), use a shorter staging prefix
  so long table names stay within MySQL's 64 characters, and stop before
  merging users when nothing was imported.
- A failed job no longer attaches its archives to the report email (with
  encryption on and no passphrase, those were the plaintext files the job had
  refused to upload).

### Fixed (found by the same audit)
- **The scheduled database backup never ran when it shared its time of day
  with the file backup** (the default): only one job runs at a time and the
  second was refused without a retry. When both are due together, one job does
  both.
- **Saving the Health tab, or `wp siteguardbackup settings set`, emptied the
  list of file entities to back up**, so later scheduled file backups had
  nothing to do.
- **Importing a backup into an existing subsite with only "Uploads" ticked
  dropped all of that subsite's tables** and imported nothing.
- **Only the first account of a storage method received backups.** A second
  account (and "Enable this instance" unticked on the first) was ignored.
  Backups now go to every enabled account; deletes and restores look at all of
  them.
- With several destinations, one success counted as success for all: the job
  was "complete" and, with "delete local copies" on, the local files were
  removed although a destination had failed. Any failed destination now makes
  the job a warning and keeps the local copies.
- Hard failures (database dump failed, disk full, no files produced) sent no
  email and no webhook, so "errors only" recipients never heard about them.
- **The encryption passphrase was altered on save** (`sanitize_text_field`
  removed tags, `%XX` sequences and repeated spaces), so backups were encrypted
  with a different passphrase than the one written down. It is stored as typed.
  A restore that fails with the typed passphrase also tries the form older
  versions would have stored.
- Storage secrets were altered the same way: an Azure SAS token (always
  contains `%XX`) could not work, and a password containing `<` was cut short.
  Secret fields and URLs are stored as typed.
- Values sent by the settings page lost their backslashes (unslashed twice):
  passwords, search/replace strings, exclusion patterns.
- A lock password containing a quote or backslash could never unlock.
- "Verify" always showed "Some checks failed", also for an intact backup.
- Restoring a file entity split over several archives with "Back up existing"
  kept only the last archive's files; with "Skip existing" it skipped the rest
  of any directory the first archive had created.
- Retention counted incomplete sets (an entity failed) as good ones, so two
  bad runs could push out the last complete backup.
- "Wipe settings" left storage credentials and remote sites in the database
  and scheduled remote sends running.
- pCloud committed a truncated file as a successful upload after a read error;
  Google Drive and OneDrive did not check that the last chunk completed the
  upload.
- A failed delete on remote storage is now logged instead of ignored.
- A finished restore did not clear its progress record, so restoring the same
  set again could skip phases.
- **"Extra directories" were never backed up.** They are now part of every
  scheduled and manual backup of the site they are configured on.
- A long step (a large upload, encryption) could outlive the job lock's 15
  minutes: a second process then took the "expired" lock and worked on the
  same files. The lock is kept alive while the job makes progress.
- A database dump with tables that could not be dumped finished as
  "complete". It is now a warning, the missing tables are named, and the set
  does not count toward retention. An empty table list (a failed query) fails
  the job instead of finishing without a database.
- **Exclusions matched by prefix and substring**, so the "others" archive
  silently left out `languages/plugins/*.mo`, `mu-plugins/x/cache-manager.php`
  and any nested `cache` directory. Excludes now match whole path segments,
  and the built-in list applies at the top of wp-content only
  (tests/test-exclude-match.php).
- A tables-only or selected-table restore on a network deleted every subsite
  that was unticked (its `wp_blogs` row and its live tables), although nothing
  of it had been imported.
- **Restoring another site's backup kept that site's backup settings** when
  this site had none of its own: storage credentials, destinations, schedule,
  passphrase, remote sites. A fresh staging site restored from production then
  backed up to, and pruned, production's storage. Those settings are removed
  unless this site had them before the restore.
- Search and replace across several pairs rewrote its own output
  (`example.com` to `staging.example.com` became
  `staging.staging.example.com`). Pairs are now applied in a single pass.
- The search and replace tool changed the text typed into it (tags, `%XX`
  sequences, repeated spaces) before running.
- A subsite import that failed to restore uploads, or hit validation errors,
  was reported as complete.
- S3-compatible storage: unfinished multipart uploads left by a killed or
  timed-out attempt are aborted (at shutdown, and before the next attempt)
  instead of being billed forever.
- Restores from remote storage and WebDAV uploads had a fixed time limit for
  the whole file (5 and 10 minutes), which failed on slower links. Transfers
  are now given up only when they stall.
- Dropbox, Google Drive, OneDrive, pCloud and WebDAV reported a download cut
  short (full disk, dropped connection) as complete. Downloads are now checked
  against the size the server announced.
- Database anonymisation left every `usermeta` value as it was (names,
  billing and shipping addresses, login session tokens, application
  passwords) and missed the order address index and customer IP.
- **Migration restores are atomic.** A migration (which keeps this site's
  users), a selected-tables or tables-only restore and a network restore with
  skipped subsites ran their statements against the live tables: an error or a
  timeout halfway left the site with tables dropped and others half imported.
  They are now staged and swapped in with one `RENAME TABLE` only when every
  statement succeeded, like a full restore. The same for subsite imports
  (into a subsite, into a single site, and batch imports), which used to drop
  the target's tables first.
- **S3-compatible uploads resume after an interruption.** A multipart upload
  killed mid-way (execution time limit, memory) started again from the first
  byte on the next attempt, so a large archive could never finish on a host
  with a short time limit. The upload is recorded before the first part and the
  next attempt uploads only the parts S3 does not have. Uploads not resumed
  within a day are aborted.
- Retention never deleted anything when every set was incomplete (an entity
  failing on every run). Incomplete sets are kept only while fewer than N
  complete ones exist, and never beyond 2 x N sets in all; database and file
  retention each look only at their own failures.
- Restoring a site's own backup onto a fresh install no longer removes its
  backup settings; only another site's settings are removed after a migration.
- A database backup deferred to run with the file backup is no longer lost
  when the file backup cannot start: it runs on its own shortly after.
- A destination skipped by its conditional rules (or with every account
  disabled) no longer makes the job report that all uploads failed.
- `wp siteguardbackup import`: no longer overwrites a different file of the
  same name, marks imported sets "always keep" (the next retention pass used
  to delete them, after `--delete-source` had removed the original), and exits
  non-zero when `--restore` fails.

### Changed
- On a multisite network the main site's SiteGuard is for network
  administrators. Subsite administrators keep their own site's page.
- A subsite's scheduled backups contain its database and uploads. Shared
  plugins and themes belong in the network backup (or run the backup as a
  network administrator or with WP-CLI).

### Fixed
- **OAuth sign-in (Google Drive, Dropbox, OneDrive) never completed.** The
  redirect URI pointed at `admin-ajax.php?action=sgbackup_ajax&subaction=...`,
  which only reads POST and answered "Missing subaction", so the code was never
  exchanged. Sign-in now returns to a REST route without a query string,
  `/wp-json/sgbackup/v1/oauth/callback` (Microsoft rejects query strings in
  redirect URIs for personal accounts). The request is authorized by a random,
  single-use state stored server-side for 15 minutes and bound to the admin,
  the provider and the account (instance). Each OAuth panel shows the exact
  redirect URI to register. Plain permalinks get a warning.
- Only the first account of a provider could ever be signed in or tested:
  "Sign in" and "Test Connection" now act on the account whose panel they are
  on.
- **Saving settings signed every OAuth account out** and reset the pinned SFTP
  host key: the save replaced the whole instance with the form fields, and
  tokens are not form fields. Values the plugin stores itself (OAuth tokens,
  the SFTP fingerprint, the cached Google Drive folder) are kept while the
  settings they depend on are unchanged. Clearing the SFTP fingerprint field
  still re-learns it.
- **Dropbox: downloads (restores) and the connection test failed with HTTP
  400.** WordPress sends `Content-Type: application/x-www-form-urlencoded` with
  a body-less POST, which Dropbox rejects. The connection test reported that
  400 as success.
- **pCloud: archives over 20 MB failed to upload.** Chunks went up as a raw
  POST body, which pCloud does not accept; they are sent as PUT (as pCloud's
  protocol page and SDK do). pCloud also gets a "Sign in with pCloud" button
  (code flow, data region detected); a pasted token still works.
- **SFTP/SCP was a fatal error** ("Class phpseclib\Net\SSH2 not found") on
  every host without the ssh2 extension: the bundled phpseclib had no
  autoloader.
- **SFTP key login from the settings page always failed**: the field asks for
  the key itself, the code only accepted a file path. Pasted RSA keys (PEM,
  PKCS#8, OpenSSH, with passphrase) now work; Ed25519/ECDSA keys, which the
  bundled library cannot read, get a clear message.
- **FTPS did not work** against servers that require TLS session reuse on the
  data connection (vsftpd, ProFTPD, FileZilla Server): PHP's ftp extension
  cannot reuse the session. FTP now goes through curl: explicit FTPS, implicit
  FTPS on port 990 (previously refused), and the server certificate is
  verified with WordPress' CA bundle (it was never verified). The ftp
  extension remains a fallback. Without either, FTP reports that instead of a
  fatal error. The FTP listing no longer includes `.` and `..`.
- **Backblaze B2 settings were silently discarded**: B2 had a settings panel
  but was missing from the save handler's allow-list, so its settings and the
  B2 destination were dropped, and its secret key was not encrypted at rest.
- **Configuring the Email destination made every backup fail**, after all
  uploads, with "is_email(): array given": its settings were stored in
  `sgbackup_email`, the report address option. They now live in
  `sgbackup_email_destination` (moved automatically on first use), and the
  Email destination has a settings panel with its own address.
- OneDrive and WebDAV had no settings panel. OneDrive gains a tenant setting
  for single-tenant app registrations and asks for `Files.ReadWrite` instead of
  `Files.ReadWrite.All`.

### Tests
- `tests/integration/storage.sh` covers every destination: versitygw (S3,
  generic S3, B2, GCS), Azurite, WebDAV, OpenSSH (SFTP, SCP, key file, pasted
  key), ProFTPD (FTP, explicit and implicit FTPS, certificate verification,
  ext-ftp fallback), Swift SAIO behind Keystone v3 and v2.0, Mailpit, and
  protocol fakes for Dropbox, Google Drive, OneDrive and pCloud (US and EU),
  plus the full OAuth sign-in flow and a backup job to five destinations.
  MinIO images are no longer published, so S3 tests use versitygw.
- `tests/test-method-registry.php` (CI) checks that every storage method is
  registered consistently: settings save allow-list, settings panel,
  "configured" list, and encryption of its secret fields.

## 1.6.6

### Fixed
- A backup whose first resumption was lost right after boot stayed at
  `begun` forever, and every later backup on that site was refused with
  "A backup job is already running" (sync API `409 busy`). The resumption
  event is written to the cron option at boot; a `wp cron event run` (the
  wp-cron container) that loaded the option a moment earlier saves its older
  copy back when it reschedules a recurring event, which drops the backup's
  event. Seen on two sites on 9 and 10 November 2026, both in the same second
  as an `action_scheduler_run_queue` run.
  - Sync API `GET /jobs/<nonce>`: a `begun` job with no queued resumption
    more than 2 minutes after boot gets resumption 0 queued again, the same
    way a stalled `running` job already got its next one.
  - A job held only by the active-job marker, with nothing queued and no
    activity for 6 hours (`sgbackup_stale_job_after` filter), is marked
    aborted and the marker is released, so the next backup can start. The
    abort is written to the job's errors, the PHP error log and the audit log
    (`backup_stale_aborted`).

## 1.6.5

### Fixed
- Fatal "Class SiteGuard_Backup not found" from the sync API ping and the
  health paths when a scheduled backup resumption is still in WP-Cron (for
  example one left by a restore): the job scheduler now loads the core class
  before it checks those jobs, as its other fallback branch already did.

## 1.6.4

### Added
- Node key for the health API: `wp siteguardbackup health_api_key --set-node`
  (read from STDIN) stores a second key, held by the agent of the docker node
  that hosts the site (wpops). It is accepted next to the site's own key, which
  it never replaces; `--clear-node` removes it. `/health/ping` reports
  `key.source: "node"` for it, and `health_api_key` (text and JSON) shows its
  hint and fingerprint. Restores keep this site's node key. This lets one node
  track every site it hosts, one install per site, without sharing or
  replacing the keys the sites already use.
- `/health` returns `environment`: WordPress and PHP versions, whether the
  install is a multisite, and the time and count of local backups. Additive;
  `api_version` stays 2.

## 1.6.3

### Security
- Sync API: the key check's per-request cache was not tied to the request. A
  PHP process that served more than one REST request (internal
  `rest_do_request()` calls, persistent PHP workers) reused the first
  request's verdict, so a later request with a wrong key could be let in. The verdict is now kept only for the same request
  object and Authorization header, like the health API's.

## 1.6.2

### Fixed
- Fatal "Class SiteGuard_Backup not found" from the sync API ping/health paths
  when `sgbackup_active_job_nonce` is set: the job scheduler's fallback branch
  now loads the core class before using it.
- Post-restore cleanup clears a job marker inherited from the source site's
  database (a dump taken while a backup ran there).

## 1.6.1

### Changed
- Docs: README and tests/README describe the merge-request pipelines and list all six unit tests. No plugin code changes.

## 1.6.0

### Sync API (pull backups from a site)
- New REST routes under `sgbackup/v1/sync/` for a trusted puller (the wpops
  node agent): start a full backup, poll the job, list complete sets with
  file sizes (and SHA-256 on request), download files (`/file?name=`) with HTTP `Range`
  (resumable through proxies with request time limits, e.g. Azure App
  Service), delete a set once copied. Multisite answers on the main site
  only; a set contains every blog.
- Separate sync key, stored hashed; `wp siteguardbackup sync_api_key
  [--regenerate|--delete]`. Failed authentications share the health API's
  per-IP limiter; every response is `Cache-Control: no-store`. A key hash can
  be pinned with `SGBACKUP_SYNC_API_KEY_HASH` in wp-config.php for hosts
  without WP-CLI.
- The set listing includes the network's site list (blog id, domain, path)
  so a puller can map subsites when restoring elsewhere.

### Fixes
- A backup resumption executed by `wp cron event run` (WP-CLI with
  `DOING_CRON`, the usual wp-cron container setup with `DISABLE_WP_CRON`)
  never queued the next resumption: WP-CLI skips the in-process chain and
  only the interactive `backup` command loops itself, so cron-driven backups
  stalled after the first batch. The next resumption is now scheduled in
  that case, and the sync API's job endpoint re-queues a running job that
  has no pending resumption.
- Restore: the backup's table prefix is decided from the dump's table list, and
  the URL pre-scan reads the whole dump. A multisite dump lists `wp_10_*`
  before `wp_*`, so the old 200 000-line scan limit left the prefix at a
  subsite's (`wp_10_`) and a whole-network restore was refused with a
  "table prefix mismatch".

## 1.5.5

- CI: pipelines run on merge requests (tests/Linux builds; Windows/macOS manual;
  nothing published).

## 1.5.4

### Security fixes from a review
- Client IP: `CF-Connecting-IP` is honoured only when the connection comes
  from a Cloudflare edge or loopback. Up to 1.5.3 any private or link-local
  peer (10/8, 172.16/12, 192.168/16, 169.254/16, fc00::/7, fe80::/10) was
  trusted too, so a host on the same LAN or Docker network could pick its own
  IP, dodge the health API rate limiter or lock out the bot's IP. Proxies in
  front of PHP (cloudflared, an nginx container) are listed with the new
  `SGBACKUP_TRUSTED_PROXIES` constant in `wp-config.php` (comma-separated IPs
  or CIDRs). **Sites behind a local proxy or tunnel must set it**, or every
  visitor shares the proxy's IP for the IP allowlist, rate limits and audit
  log. Sites reached straight from Cloudflare or directly are unchanged.
  `X-Forwarded-For` is still never used.
- Health REST API: every response of `/health*`, errors included, sends
  `Cache-Control: no-store, private, max-age=0` and `Vary: Authorization`.
- Health REST API: the key check ran twice per request (WordPress checks
  permissions again for the `Allow` header), doubling the bcrypt work and
  counting each failed attempt twice, so the limiter answered 429 after 10
  bad keys instead of 20. It runs once per request now. IPv6 clients are
  rate-limited per /64.
- Uptime Kuma push: redirects are no longer followed (was up to 2), so a
  push URL cannot bounce the heartbeat and its token to another host.

### SSL expiry off by one
- Days to expiry are rounded down, so a certificate that expired less than a
  day ago read as -1, the "unknown" value: the site stayed operational. One
  with hours left read as 0 and was marked expired. The health monitor now
  records the certificate's notAfter (`ssl_expires_at`, new in the REST API)
  and decides expiry from it; less than a day left is "expiring" (degraded,
  critical `ssl_expiring` alert), not expired.
- REST API: `ssl_expired` alerts and `summary.ssl_expired` only fired at
  exactly 0 days; they now fire for every expired certificate.
- The cURL probe treated a certificate that expired within the last day as
  "unable to parse expiry".
- The time-budget skip's SSL message names the domain, and the Health tab
  shows "Expired" instead of "N/A" for an expired certificate.

## 1.5.3

### Uptime Kuma push for the health monitor
- After every health run (cron, Check Now, `wp siteguardbackup health_check`,
  `POST /health/check`) SiteGuard can send one heartbeat to an Uptime Kuma
  Push monitor: `status=up|down`, a message of at most 200 characters naming
  the failing sites with reasons, and the run time as `ping`. Down when any
  site is down, a certificate expired or expires within 3 days (the REST
  API's critical alerts); slow sites, SSL probe failures and certificates
  expiring later stay up and are named in the message. A run that throws
  still pushes (down), and a failed push never affects the run.
- Health tab: "Uptime Kuma Push" section with the push URL (masked after
  saving), Send test push, Remove, the last push result and the Kuma
  heartbeat interval to use; warns when WP-Cron is not driven by a system
  cron or the last health run is more than two intervals old. Network
  administrators only on multisite.
- WP-CLI: `wp siteguardbackup health_kuma [--push-url=<push url>] [--test] [--clear]`;
  `health_status`, `health_check` and `health_config` show the masked URL and
  last push.
- The push URL is validated (https, path ending in `/api/push/<token>`), never
  logged or returned in full, and not part of the REST API, webhooks or a
  settings export. Push failures are logged once per change, with the URL
  masked.
- Docs: `docs/uptime-kuma.md` (Kuma monitor settings, message format, cron).
- Tests: `tests/test-health-kuma.php` (URL validation, masking, message
  building) and `tests/integration/health-kuma.sh` (against a fake Kuma).
## 1.5.2

### Interrupted restores keep this site's settings
- A restore keeps the backup's timestamp from the start. It used to look the
  timestamp up in history, which after the database import is the backup's:
  progress and the search-replace checkpoint were then saved under `0`, a
  resumed migration discarded its own checkpoint ("Discarding stale
  search-replace checkpoint") and skipped the search-replace.
- A progress record without a timestamp, or with another backup's, is no longer
  treated as this restore's. A stale record from an interrupted restore could
  make a different backup skip its database. An interrupted 1.5.1 restore that
  is still pending when this version is installed imports its database again
  instead of resuming.
- This site's SiteGuard settings (storage credentials, migration keys, history,
  failover role, health monitor settings) are saved to the database right after
  the import (`sgbackup_restore_preserved`, removed when the restore finishes,
  ignored after 24 hours or for another backup). A restore that was interrupted
  and resumed, or continued by the browser after a search-replace checkpoint,
  used to lose them and keep the backup's.
- This site's backup history is put back right after the import, so resuming a
  restore after its database phase no longer fails with "Backup not found". The
  progress record also keeps the backup set as a fallback.
- A resume whose run died between the import and the first search-replace pass
  imports the database again (reusing the saved settings) instead of running a
  search-replace with no URLs.
- A resumed network migration keeps each subsite's own SiteGuard rows.
- Lock rows (`sgbackup_restore`, `sgbackup_lock`, `sgbackup_boot`,
  `sgbackup_history`, `sgbackup_sem_*`) are never saved or written back with
  this site's settings, so a resumed restore cannot put the interrupted run's
  restore lock back. A restore lock that came in with the imported database
  (a pre-restore backup is dumped while its restore holds the lock) is removed
  at the end of the restore.
- A browser restore whose search-replace checkpoint cannot be saved (for
  example a row larger than `max_allowed_packet`) finishes the search-replace
  in the same request, instead of relying on a checkpoint that is not there.

### Health monitor key survives restores and failover
- Why the bot lost access: backups always held the monitor key's hash, but
  every restore wrote this site's SiteGuard settings back over the backup's,
  the health key and switch included. A standby that had ever saved its Health
  tab without a key ended up disabled (`404 disabled`), and one with its own
  key refused production's (`403`).
- The monitor key and its settings (enabled, interval, timeout, SSL warning
  days) are now decided as one unit, right after the import: **keep** this
  site's key (default) or use the **backup**'s. If this site has no key, the
  backup's is used either way, and the monitor is enabled if it was in the
  backup. A backup without a key never leaves the site keyless. Every other
  SiteGuard setting keeps this site's value, as before.
- The choice is offered in the restore dialog, as `--health-key=keep|backup`
  on `wp siteguardbackup restore` and `import --restore`, in restore presets
  and in the generic restore command, with a site default under Settings
  ("Default Health Monitor Key on Restore"). Resumed restores keep the
  decision made at import time.
- A plaintext key from before 1.3.13 in an old backup no longer replaces this
  site's key; a stale plaintext row next to a hash is dropped instead of
  overwriting the hash.
- A subsite restored into a single site without a key keeps the subsite's key
  (it used to be dropped).
- On a network whose main site keeps its monitor settings in `wp_sitemeta`, a
  backup's per-site setting row (for example `enabled=0`) no longer hides this
  site's setting: the kept key answered `404 disabled`.
- The scheduled health check is set to the restored interval (or removed when
  the monitor is off) at the end of a restore, and cached rate-limit counters
  are cleared.
- Keys carry a fingerprint (`sgk-` + 12 hex digits of SHA-256, computable
  locally) and hint; the Health tab and `wp siteguardbackup health_api_key`
  show them, where the key came from, and the last restore's result.
  `/health/ping` reports the fingerprint of the key that authenticated.
- "Switch to the backup's key" (Health tab, network admins, within 7 days) and
  `health_api_key --adopt-restored` use the backup's key a restore did not
  apply.
- One key for production and a standby: `SGBACKUP_HEALTH_API_KEY_HASH` (and
  `SGBACKUP_HEALTH_ENABLED`) in `wp-config.php` are honoured on the main site
  whatever a restore or raw database sync does; `health_api_key --print-hash`
  prints the define, `--set` stores a key read from STDIN.
  A pinned hash must be complete: one cut short (Docker Compose reads `$` in
  `WORDPRESS_CONFIG_EXTRA` as a variable) is reported as malformed instead of
  shown as a working pin. `SGBACKUP_HEALTH_ENABLED` must be a boolean; a
  string such as `'false'` is ignored with a warning instead of turning the
  monitor on.
- A subsite restored into a single site that keeps its own key keeps its own
  health settings too; the subsite's (for example `enabled=1`) no longer fill
  in settings this site had no row for.
- A plaintext key from before 1.3.13 on this site is kept in the restore's
  saved state only as a hash.
- After "Switch to the backup's key", the last-restore line says the switch
  was made instead of still saying the backup's key was not applied.
- Docs: "Keys, backups, restores and failover" in `docs/health-api.md`, and the
  client IP note corrected (`CF-Connecting-IP` only from a trusted proxy).

## 1.5.1

Released 2026-09-24. Build and release tooling only; the plugin code is
unchanged from 1.5.0.

### Build and release on GitLab
- GitHub Actions is disabled for this project, so testing, packaging and
  releasing now run as a GitLab CI pipeline (`.gitlab-ci.yml`), started by
  pushing a `v<version>` tag or by hand from the GitLab web UI or API.
- Test: every PHP file outside `vendor/` is linted on PHP 8.3, then the
  standalone unit tests (delete guard, encryption) run as an unprivileged
  user (`ci/test.sh`). A lint failure names the file and shows the error.
- Package: `ci/build-zip.sh` builds the same `siteguardbackup/` zip as the old
  GitHub workflow, leaving out tests, docs and CI files. A tagged build stops
  unless the plugin header, `SGBACKUP_VERSION` and a CHANGELOG section all
  match the tag. Files in the zip are always 0644 and folders 0755, whatever
  the runner's umask, and the build fails if anything is group- or
  world-writable.
- Release: `ci/release.sh` uploads the zip to the project's Generic Package
  Registry and creates or updates the GitLab release, using this version's
  CHANGELOG section as the notes. Running it again updates the release in
  place and never deletes release links or packages.
- Job images are pinned (`php:8.3-cli-bookworm`, Alpine 3.22).

### Documentation
- README: how to cut a release on GitLab (push a tag whose version matches the
  plugin header, `SGBACKUP_VERSION` and CHANGELOG.md), where the zip ends up,
  and how this differs from the old GitHub workflow: pushes to master no longer
  release automatically, and a tag pushed with `ci.skip` never releases.
- tests/README.md points to the GitLab test job instead of the disabled GitHub
  workflow; local test runs still go through the sandbox.

## 1.5.0

A safety, correctness and migration release. Everything below was verified on
live single-site and multisite WordPress instances (see `tests/`).

### Safety: deletes can no longer escape their directory
- All recursive and file deletes go through one guard that refuses empty,
  relative, `/`, system and escaping paths, and any root that is or contains the
  WordPress install. Symlinks are removed as links and never followed; the first
  failure stops the walk and is reported instead of being suppressed with `@`.
  Previously one restore helper had no containment check at all, and others
  matched `/backups2` as inside `/backups`.
- The backup directory can never resolve to `/`, a system directory, a web root
  or an ancestor of WordPress (an empty `sgbackup_backup_dir` filter used to
  yield `/`). Nothing is created before the check passes.
- History deletes never remove an archive another backup still references.
- Remote deletes refuse empty, `.`, `..` and path names: on OneDrive, Dropbox
  and WebDAV one bad name would have deleted the whole backup folder.
- Legacy in-webroot cleanup keeps the deny files while archives remain.

### Restores
- Database restores no longer die with a fatal error (broken since 1.4.16).
- A failed database restore is not recorded as done; a retry no longer skips
  the database and reports success.
- A failed database phase stops the restore before any file is overwritten.
- The live file is only replaced once its restored copy is complete.
- The running SiteGuard plugin is never overwritten, and stays active after a
  restore (a backup from a site where it was network-active switched it off).
- Rewrite rules are rebuilt from the restored permalink structure (posts 404'd
  when source and destination structures differed), on every subsite.
- Imported unfinished jobs are closed properly; SiteGuard settings, history and
  schedules of the destination (and of each subsite in a network restore) are
  kept.
- The pre-restore safety backup is complete or the restore does not start, and
  is not repeated on resume.
- The admin "resume" no longer runs the whole restore twice.
- Extra directories restore only into the directory they were backed up from.
- Duplicate plugins are no longer deleted by name; only a second active copy is
  deactivated.
- An interrupted atomic table swap is recovered instead of dropped.

### Moving sites (every direction now works)
- New: a multisite subsite restores into a single site.
- Single site / main site into a subsite: uploads URLs follow the files,
  roles come across, the source's plugins are activated.
- Network to network: the destination domain is no longer rewritten onto
  itself (`dst.test` -> `dst.dst.dst.test`).
- Source users are merged instead of dropped, with their roles, password
  hashes and authorship; importing no longer turns every user into a
  subscriber.
- Main-site plugin tables such as `wp_404_to_301` are no longer mistaken for
  subsite tables.

### Backup, download and upload
- A finished backup no longer leaves a phantom "running" job that refuses the
  next backup.
- Backup uploads on multisite are no longer capped at the network's media size
  (1 MB); uploads and bundle extraction never overwrite an existing backup,
  verify every write, and keep the uploaded bundle on failure.
- Retention: an extra rule for one type no longer deletes the other type's
  archives (a db rule removed file backups, locally and remotely).
- `migrate_send` no longer adds a phantom history entry per send (which counted
  toward retention) and no longer reports success when every upload failed;
  resumed sends verify the destination's copy.
- Encryption requires the whole file to be read (a read error produced a valid
  encryption of a partial archive and the original was then deleted);
  decryption refuses anything incomplete.
- Foreign `.tar.gz` imports are no longer treated as database dumps.

### Security
- Subsite administrators can only back up, download and restore their own
  subsite; network-wide actions and network backups need a network admin.
- Stored secrets (including the SFTP private key) are never rendered into the
  settings page.
- SFTP verifies the host key before sending credentials and fails closed.
- Explicit FTPS never falls back to plaintext; WebDAV and OpenStack warn about
  plain `http://` endpoints.
- Imported SQL cannot leave the site's database (`USE`, `CREATE/DROP
  DATABASE`, tables qualified with another database).

### Storage
- S3: SigV4 canonical requests, multipart sizing, region redirects and clock
  skew handling, a provider matrix (AWS, Spaces, MinIO, DreamObjects, B2) and an
  explicit "allow insecure" option for private http endpoints.
- Azure: SAS token support, Content-MD5 and post-upload size checks.
- Downloads from every provider are checked for completeness.

### Tests
- `tests/`: sandboxed unit tests (delete guard, encryption), run in CI.
- `tests/integration/`: browser workflow (Backup Now, download, upload,
  restore), all transfer directions, storage against MinIO, Azurite and
  WebDAV, and the plugin's destructive paths with a filesystem fingerprint.
  Integration tests run only on disposable Docker hosts.
