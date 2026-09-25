# Changelog

## Unreleased

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
