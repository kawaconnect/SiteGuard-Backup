# Changelog

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
