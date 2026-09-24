#!/usr/bin/env bash
# Lint every PHP file and run the standalone unit tests (delete guard,
# encryption), the same checks as .github/workflows/tests.yml.
#
# The unit tests call real delete functions and refuse to run as root (see
# tests/README.md). CI job containers start as root, so when this script runs
# as root it drops to `nobody` for the tests with setpriv (util-linux, present
# in the Debian-based php images; the same call tests/run-sandboxed.sh makes).
# As a normal user it runs them directly.
#
# This is the entry point for CI and throwaway containers only. It does not
# build the read-only sandbox; on a real host use `sudo tests/run-sandboxed.sh`.
#
#   ci/test.sh                 # from the repository root
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

php -v | head -n 1

echo "== lint"
count=0
while IFS= read -r -d '' f; do
	# php-cli without a php.ini prints parse errors on stdout; show them.
	php -l "$f" >/dev/null || { echo "lint failed: $f" >&2; php -l "$f" >&2 || true; exit 1; }
	count=$((count + 1))
done < <(find . -name '*.php' -type f -not -path './vendor/*' -not -path './.git/*' -print0 | sort -z)
[ "$count" -gt 0 ] || { echo "no PHP files found to lint" >&2; exit 1; }
echo "linted $count PHP files"

if [ "$(id -u)" -eq 0 ]; then
	command -v setpriv >/dev/null || { echo "running as root and setpriv is missing; refusing to run the tests as root" >&2; exit 1; }
	nobody_uid=$(id -u nobody)
	nobody_gid=$(id -g nobody)
	run_unprivileged() { setpriv --reuid="$nobody_uid" --regid="$nobody_gid" --clear-groups --no-new-privs -- "$@"; }
	run_unprivileged test -r includes/class-filesystem-functions.php ||
		{ echo "nobody cannot read the checkout at $PWD; the tests cannot run unprivileged" >&2; exit 1; }
	echo "running the unit tests as nobody"
else
	run_unprivileged() { "$@"; }
fi

rc=0
for t in tests/test-*.php; do
	echo "== $t"
	run_unprivileged php "$t" || rc=1
done
exit "$rc"
