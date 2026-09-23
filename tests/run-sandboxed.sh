#!/usr/bin/env bash
# Run SiteGuard's standalone PHP tests in a contained environment.
#
# The tests call real delete functions. This wrapper makes sure that even a
# broken guard cannot touch the host:
#   - a private mount namespace in which / is bind-remounted READ-ONLY
#   - a fresh, size-limited tmpfs on /tmp (the only writable place)
#   - the tests themselves run as nobody, never root
#   - one run at a time (lock), so two runs never share a fixture
#
# Usage (as root, because creating the namespace needs it):
#   tests/run-sandboxed.sh                 # all tests/test-*.php
#   tests/run-sandboxed.sh tests/test-delete-guard.php

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(dirname "$here")"

if [ "$(id -u)" -ne 0 ]; then
	echo "run-sandboxed.sh needs root to build the namespace (tests then drop to nobody)." >&2
	echo "Or run a single test directly as an unprivileged user: php tests/test-delete-guard.php" >&2
	exit 2
fi
for bin in unshare setpriv php flock; do
	command -v "$bin" >/dev/null || { echo "missing required tool: $bin" >&2; exit 2; }
done

if [ "$#" -eq 0 ]; then
	set -- "$here"/test-*.php
fi
for t in "$@"; do
	case "$(realpath -- "$t")" in
		"$repo"/tests/test-*.php) ;;
		*) echo "refusing: $t is not a tests/test-*.php file in this repo" >&2; exit 2 ;;
	esac
done

exec 9>/run/siteguard-tests.lock
flock -n 9 || { echo "another SiteGuard test run is in progress; not running concurrently" >&2; exit 2; }

nobody_uid="$(id -u nobody)"
nobody_gid="$(id -g nobody)"

unshare --mount --propagation private -- bash -euo pipefail -c '
	mount --bind / /
	mount -o remount,bind,ro /
	mount -t tmpfs -o size=64m,mode=1777 tmpfs /tmp
	if touch /.siteguard-rw-probe 2>/dev/null; then
		echo "sandbox check failed: / is writable; aborting" >&2
		exit 3
	fi
	uid="$1"; gid="$2"; shift 2
	rc=0
	for t in "$@"; do
		echo "== $t"
		setpriv --reuid="$uid" --regid="$gid" --clear-groups --no-new-privs -- php "$t" || rc=1
	done
	exit "$rc"
' sandbox "$nobody_uid" "$nobody_gid" "$@"
