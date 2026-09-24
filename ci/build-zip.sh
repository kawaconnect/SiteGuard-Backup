#!/usr/bin/env bash
# Build the installable plugin zip, the same layout as the GitHub release
# workflow: a single siteguardbackup/ folder, without the repository-only
# files (git and CI metadata, docs/, tests/).
#
#   ci/build-zip.sh [VERSION] [OUT_DIR]
#
# VERSION is the release version without the leading "v" (1.5.1). When given,
# the plugin header "Version:" and SGBACKUP_VERSION must both equal it, and
# CHANGELOG.md must have a "## VERSION" section, so a tag can never publish a
# zip that reports another version inside WordPress. The legacy GitHub tag form
# <version>-<sha7> (v1.5.0-9ffc7c1) is accepted and checked as <version>.
# Without VERSION the zip is named after the plugin header version.
#
# Writes OUT_DIR/siteguardbackup-<version>.zip (OUT_DIR defaults to ./out).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
version="${1:-}"
out="${2:-out}"

header_version=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' siteguardbackup.php | head -n 1)
const_version=$(sed -n "s/^define('SGBACKUP_VERSION',[[:space:]]*'\([^']*\)');.*/\1/p" siteguardbackup.php | head -n 1)
[ -n "$header_version" ] || { echo "could not read the Version: header from siteguardbackup.php" >&2; exit 1; }
[ -n "$const_version" ] || { echo "could not read SGBACKUP_VERSION from siteguardbackup.php" >&2; exit 1; }
echo "plugin header Version: $header_version, SGBACKUP_VERSION: $const_version"

if [ -n "$version" ]; then
	plugin_version="$version"
	case "$version" in
		"$header_version"-[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]) plugin_version="$header_version" ;;
	esac
	[ "$header_version" = "$plugin_version" ] ||
		{ echo "siteguardbackup.php Version: is $header_version but this release is $plugin_version: bump the header and SGBACKUP_VERSION first" >&2; exit 1; }
	[ "$const_version" = "$plugin_version" ] ||
		{ echo "SGBACKUP_VERSION is $const_version but this release is $plugin_version: bump it to match the header" >&2; exit 1; }
	grep -qx "## $plugin_version" CHANGELOG.md ||
		{ echo "CHANGELOG.md has no '## $plugin_version' section for this release" >&2; exit 1; }
else
	version="$header_version"
	[ "$const_version" = "$header_version" ] ||
		echo "warning: SGBACKUP_VERSION ($const_version) differs from the header ($header_version)" >&2
fi

stage=$(mktemp -d)
mkdir "$stage/siteguardbackup"
rsync -a --chmod=D755,F644 \
	--exclude='.git/' \
	--exclude='.github/' \
	--exclude='.gitlab-ci.yml' \
	--exclude='/ci/' \
	--exclude='/out/' \
	--exclude='.gitignore' \
	--exclude='.DS_Store' \
	--exclude='docs/' \
	--exclude='tests/' \
	./ "$stage/siteguardbackup/"

# The zip must be a loadable plugin: main file present, nothing test-only.
test -f "$stage/siteguardbackup/siteguardbackup.php" || { echo "siteguardbackup.php missing from the package" >&2; exit 1; }
test -f "$stage/siteguardbackup/vendor/phpseclib/bootstrap.php" || { echo "bundled phpseclib missing from the package" >&2; exit 1; }
test ! -e "$stage/siteguardbackup/tests" || { echo "tests/ leaked into the package" >&2; exit 1; }

mkdir -p "$out"
zip_path="$(cd "$out" && pwd)/siteguardbackup-$version.zip"
test ! -e "$zip_path" || { echo "$zip_path already exists; not overwriting it" >&2; exit 1; }
(cd "$stage" && zip -q -r -X "$zip_path" siteguardbackup)
unzip -tq "$zip_path"
# Installed plugin files must not be group/world-writable (the runner checks out with umask 0000).
bad_modes=$(unzip -Z "$zip_path" | awk '$1 ~ /^[-d]/ && (substr($1,6,1) == "w" || substr($1,9,1) == "w") {print $NF}')
[ -z "$bad_modes" ] || { echo "group- or world-writable entries in the package:" >&2; echo "$bad_modes" >&2; exit 1; }
echo "built $zip_path ($(du -h "$zip_path" | cut -f1), $(unzip -Z1 "$zip_path" | wc -l) entries)"
