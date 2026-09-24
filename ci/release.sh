#!/bin/sh
# Publish out/siteguardbackup-<version>.zip to this project's Generic Package
# Registry and create (or update) the GitLab release for the version's tag,
# with this version's CHANGELOG.md section as the notes. GitLab CI only; runs
# in the release job with CI_JOB_TOKEN. Needs curl and jq.
#
# Re-running for the same tag re-uploads the zip and updates the release's
# notes and link in place; it never removes anything.
set -eu

VERSION="${RELEASE_VERSION:-${CI_COMMIT_TAG:-}}"; VERSION="${VERSION#v}"
[ -n "$VERSION" ] || { echo "no CI_COMMIT_TAG or RELEASE_VERSION; nothing to release"; exit 1; }
TAG="v$VERSION"
API="$CI_API_V4_URL/projects/$CI_PROJECT_ID"
H="JOB-TOKEN: $CI_JOB_TOKEN"
PKG="$API/packages/generic/siteguardbackup/$VERSION"

# Publish only from a pipeline on the tag itself, so the zip is the tag's
# commit. Checked locally, so the job token is only used for the Packages and
# Releases APIs.
[ "${CI_COMMIT_TAG:-}" = "$TAG" ] || { echo "This pipeline runs on '${CI_COMMIT_TAG:-$CI_COMMIT_REF_NAME}'. Run the pipeline on the tag ref $TAG."; exit 1; }

# Legacy GitHub-style tags (v1.5.0-9ffc7c1) carry the plugin version before the suffix.
PLUGIN_VERSION=$(printf '%s' "$VERSION" | sed 's/-[0-9a-f]\{7\}$//')
ZIP="out/siteguardbackup-$VERSION.zip"
[ -f "$ZIP" ] || { echo "$ZIP was not built"; ls -la out/ || true; exit 1; }

name=$(basename "$ZIP")
echo "uploading $name ($(du -h "$ZIP" | cut -f1))"
curl -fsS -H "$H" --upload-file "$ZIP" "$PKG/$name" >/dev/null
LINK=$(jq -cn --arg n "$name" --arg u "$PKG/$name" '{name:$n, url:$u, link_type:"package"}')

NOTES=$(awk -v v="## $PLUGIN_VERSION" '$0 == v {on=1; next} /^## / && on {exit} on && !seen && /^[ \t]*$/ {next} on {seen=1; print}' CHANGELOG.md)
[ -n "$NOTES" ] || NOTES="Version $PLUGIN_VERSION"
NOTES=$(printf '%s\n\nInstall: WordPress > Plugins > Add New > Upload Plugin, then choose %s.\n\nCommit %s, built on GitLab CI.' "$NOTES" "$name" "$CI_COMMIT_SHA")

if curl -fsS -o /dev/null -H "$H" "$API/releases/$TAG" 2>/dev/null; then
	BODY=$(jq -cn --arg name "SiteGuard Backup $PLUGIN_VERSION" --arg desc "$NOTES" '{name:$name, description:$desc}')
	curl -fsS -X PUT -H "$H" -H "Content-Type: application/json" "$API/releases/$TAG" -d "$BODY" | jq -r '"updated release " + .tag_name'
	LINK_ID=$(curl -fsS -H "$H" "$API/releases/$TAG/assets/links" | jq -r --arg n "$name" '[.[] | select(.name == $n)][0].id // empty')
	if [ -n "$LINK_ID" ]; then
		curl -fsS -X PUT -H "$H" -H "Content-Type: application/json" "$API/releases/$TAG/assets/links/$LINK_ID" -d "$LINK" >/dev/null
	else
		curl -fsS -X POST -H "$H" -H "Content-Type: application/json" "$API/releases/$TAG/assets/links" -d "$LINK" >/dev/null
	fi
else
	BODY=$(jq -cn --arg tag "$TAG" --arg name "SiteGuard Backup $PLUGIN_VERSION" --arg desc "$NOTES" --argjson link "$LINK" \
		'{tag_name:$tag, name:$name, description:$desc, assets:{links:[$link]}}')
	curl -fsS -X POST -H "$H" -H "Content-Type: application/json" "$API/releases" -d "$BODY" | jq -r '"created release " + .tag_name'
fi
echo "Published $TAG with $name"
