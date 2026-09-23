# Testing SiteGuard safely

SiteGuard deletes things for a living: retention pruning, restore-overwrite,
temp cleanup, uninstall. A bug in any of these that resolves a path wrongly can
walk and delete from `/`. That has happened. On 2026-09-23 a parallel test run,
executed as root on a management container, destroyed the container's entire
root filesystem, including the only copies of its config and keys.

These rules are not optional.

## Rules

1. **Never run destructive tests on a host that holds unique state.** Use a
   throwaway VM or container, and snapshot it before each run
   (`rbd snap create`, `pct snapshot`, etc.).
2. **Never run the plugin or its tests as root.** Use `wp` without
   `--allow-root`, as the web user or a dedicated unprivileged user that
   can only write inside the test site.
3. **Keep the safety net outside the blast radius.** Backups, git bundles and
   notes you want to survive a bad run belong on another machine.
4. **Run one test run per fixture at a time.** Two runs resetting the same test
   site at once corrupt each other's state. `run-sandboxed.sh` takes a lock.
5. **Shell teardown scripts** that `rm -rf` a test site must use
   `set -euo pipefail`, quote every expansion, and assert the target first:

   ```sh
   N="${1:?site name required}"
   D="/srv/sgtest/$N"
   case "$D" in /srv/sgtest/?*) ;; *) echo "refusing: $D" >&2; exit 2 ;; esac
   rm -rf -- "$D"
   ```

## Code rules

- Every file or directory delete goes through
  `SiteGuard_Backup_Filesystem::delete_file($path, $root)` or
  `::delete_tree($dir, $root)`. They refuse empty, relative, `/`, system, or
  escaping paths, never follow symlinks, and return a `WP_Error` instead of
  failing silently.
- No `@unlink` / `@rmdir`. A failed delete must be visible.
- No hand-written `RecursiveIteratorIterator` delete loops.

## Running the standalone tests

```sh
sudo tests/run-sandboxed.sh          # read-only /, tmpfs /tmp, runs as nobody
php tests/test-delete-guard.php      # or directly, as an unprivileged user
```

The PHP tests refuse to run as root or from a web request. `tests/` is excluded
from the release zip.
