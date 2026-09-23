#!/usr/bin/env bash
# =============================================================================
# SiteGuard site-transfer matrix against disposable WordPress instances.
#
#   tests/integration/transfer.sh <instance-manager> <plugin-dir> [scenario ...] [--method file|remote|both] [--keep|--keep-failed]
#
# Creates two seeded source instances (single site + multisite) and, for every
# scenario and transport, a FRESH destination instance that is destroyed
# afterwards. Transports:
#   file   - copy the archives into the destination, `siteguardbackup import`
#   remote - migration key on the destination, `migrate_send` from the source
#            over the instances' internal mesh network
# Then `siteguardbackup restore` on the destination and a full verification.
#
# Scenarios:
#   s2s   single site          -> single site
#   s2mn  single site          -> multisite, as a NEW subsite
#   s2me  single site          -> multisite, over an EXISTING subsite
#   m2s   multisite subsite    -> single site
#   mm2s  multisite main site  -> single site (network backup, --subsite-mode=main-only)
#   m2mn  multisite subsite    -> multisite, as a NEW subsite
#   mm2m  whole network        -> multisite network
#
# All plugin code runs inside instance containers as www-data. Needs root on
# a disposable Docker host only for docker/instance management.
# =============================================================================
set -uo pipefail

IM="${1:?instance manager (WPMultisiteDocker/scripts/instance.sh) required}"
PLUGIN="${2:?plugin directory (named siteguardbackup) required}"
shift 2
METHODS=(file remote); KEEP=0; KEEP_FAILED=0; SCEN=()
while [ $# -gt 0 ]; do
	case "$1" in
		--method) case "${2:-}" in file) METHODS=(file);; remote) METHODS=(remote);; both) METHODS=(file remote);; *) echo "bad --method" >&2; exit 2;; esac; shift 2 ;;
		--keep) KEEP=1; shift ;;
		--keep-failed) KEEP_FAILED=1; shift ;;
		*) SCEN+=("$1"); shift ;;
	esac
done
[ ${#SCEN[@]} -gt 0 ] || SCEN=(s2s s2mn s2me m2s mm2s m2mn mm2m)
[ -x "$IM" ] || { echo "not executable: $IM" >&2; exit 2; }
[ "$(basename "$PLUGIN")" = siteguardbackup ] || { echo "plugin dir must be named siteguardbackup" >&2; exit 2; }
command -v jq >/dev/null || { echo "jq required" >&2; exit 2; }

exec 9>/tmp/siteguard-transfer.lock
flock -n 9 || { echo "another transfer run is active" >&2; exit 2; }

SRC_S=xt-src-single; SRC_M=xt-src-multi
PASS=0; FAIL=0; FAILED=(); CTX=""

ok()    { PASS=$((PASS + 1)); echo "    ok   $*"; }
bad()   { FAIL=$((FAIL + 1)); FAILED+=("$CTX: $*"); echo "    FAIL $*"; }
check() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d"; fi; }
eq()    { [ "$1" = "$2" ]; }

# wp <instance> [--url=...] args  (stderr noise from update checks dropped)
wp()  { local i="$1"; shift; "$IM" wp "$i" "$@" 2> >(grep -v -e wp_update -e 'Warning: .*WordPress.org' >&2); }
sh_in() { "$IM" compose "$1" exec -T cli sh -c "$2"; }
port() { ( . "$(dirname "$IM")/../instances/$1/.env"; echo "$HTTP_PORT" ); }
http() { # instance path -> status code
	curl -s -o /dev/null -w '%{http_code}' -H "Host: $1.test" "http://127.0.0.1:$(port "$1")$2"
}
http_body() { curl -s -H "Host: $1.test" "http://127.0.0.1:$(port "$1")$2"; }

newest_ts() { # instance [url]: newest history entry that has files
	wp "$1" ${2:+--url=$2} eval '$h = SiteGuard_Backup_History::get_history_raw(); krsort($h);
		foreach ($h as $t => $b) { if (SiteGuard_Backup_History::get_all_files($b)) { echo $t; break; } }'
}

create() { # name mode
	"$IM" destroy "$1" --yes >/dev/null 2>&1 || true
	"$IM" create "$1" --mode "$2" >/dev/null 2>&1 || { echo "create $1 failed" >&2; return 1; }
	"$IM" install-plugin "$1" "$PLUGIN" --activate >/dev/null 2>&1 || { echo "plugin install on $1 failed" >&2; return 1; }
}

# Seed a site with content that every transfer must carry across.
seed() { # instance url label
	local i="$1" u="$2" label="$3" U=(--url="$2")
	sh_in "$i" 'mkdir -p wp-content/plugins/sgx-marker && printf "%s\n" "<?php" "/* Plugin Name: SGX Marker */" > wp-content/plugins/sgx-marker/sgx-marker.php \
		&& printf "%s" iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg== | base64 -d > /tmp/sgx.png'
	if wp "$i" eval 'echo is_multisite() ? 1 : 0;' | grep -q 1; then
		wp "$i" theme enable twentytwentyfour --network >/dev/null
	fi
	wp "$i" "${U[@]}" theme activate twentytwentyfour >/dev/null
	wp "$i" "${U[@]}" plugin activate sgx-marker >/dev/null
	wp "$i" "${U[@]}" option update sgx_marker "$label" >/dev/null
	# URLs inside serialized data and escaped JSON (page builders store both).
	wp "$i" "${U[@]}" eval "update_option('sgx_ser', array('link' => '${u%/}/deep/page/', 'n' => array('img' => '${u%/}/x.png', 'txt' => 'héllo 🐍 100%')));
		update_option('sgx_json', json_encode(array('link' => '${u%/}/deep/page/')));" >/dev/null
	# An editor (multisite logins: lowercase letters and digits only) who authors the marker post.
	wp "$i" "${U[@]}" user create "sgx$label" "sgx$label@example.test" --role=editor --porcelain >/dev/null
	local author; author="$(wp "$i" user get "sgx$label" --field=ID)"
	local aid; aid="$(wp "$i" "${U[@]}" media import /tmp/sgx.png --title="sgx-img-$label" --porcelain)"
	local img; img="$(wp "$i" "${U[@]}" eval "echo wp_get_attachment_url($aid);")"
	wp "$i" "${U[@]}" post create --post_type=post --post_status=publish --post_title="SGX $label" --post_author="$author" \
		--post_name="sgx-$label" --post_content="Link <a href=\"${u%/}/sgx-$label/\">self</a> img <img src=\"$img\">" >/dev/null
}

# Scope: which backup on the source.  -> sets SRC, SRC_URL, BACKUP_URL, SRC_LABEL
backup_scope() {
	case "$1" in
		single)  SRC=$SRC_S; SRC_URL="http://$SRC_S.test";         BACKUP_ARGS=();                                   SRC_LABEL=single ;;
		sub1)    SRC=$SRC_M; SRC_URL="http://$SRC_M.test/sub1";    BACKUP_ARGS=(--url="$SRC_URL/" --subsite-only);   SRC_LABEL=sub1 ;;
		main)    SRC=$SRC_M; SRC_URL="http://$SRC_M.test";         BACKUP_ARGS=();                                   SRC_LABEL=main ;;
		network) SRC=$SRC_M; SRC_URL="http://$SRC_M.test";         BACKUP_ARGS=();                                   SRC_LABEL=main ;;
	esac
}

# Transport the source's newest set to DST; echoes the destination timestamp.
transport() { # method src src_url dst
	local method="$1" src="$2" su="$3" dst="$4"
	local sts; sts="$(newest_ts "$src" "$su/")"
	[ -n "$sts" ] || { echo "no source backup" >&2; return 1; }
	if [ "$method" = file ]; then
		local x; x="$(mktemp -d /srv/sgtest/xfer.XXXXXX)"
		local files; files="$(wp "$src" --url="$su/" eval "echo implode(' ', SiteGuard_Backup_History::get_all_files(SiteGuard_Backup_History::get_history_raw()[$sts]));")"
		local sdir; sdir="$(wp "$src" --url="$su/" eval 'echo SiteGuard_Backup_Options::instance()->get_backup_dir();')"
		local sc; sc="$("$IM" compose "$src" ps -q wordpress)"; local dc; dc="$("$IM" compose "$dst" ps -q wordpress)"
		local f; for f in $files; do docker cp -q "$sc:$sdir$f" "$x/$f" || return 1; done
		docker exec -u 33 "$dc" mkdir -p /var/www/html/wp-content/sg-incoming
		docker cp -q "$x/." "$dc:/var/www/html/wp-content/sg-incoming/"
		docker exec -u 0 "$dc" chown -R 33:33 /var/www/html/wp-content/sg-incoming
		rm -rf --one-file-system -- "$x"
		wp "$dst" siteguardbackup import /var/www/html/wp-content/sg-incoming/ --delete-source >/dev/null || return 1
	else
		local key; key="$(wp "$dst" siteguardbackup create_migration_key | sed -n 2p)"
		[ -n "$key" ] || { echo "no migration key" >&2; return 1; }
		local added; added="$(wp "$src" --url="$su/" siteguardbackup migrate_add_destination "$key")" || return 1
		local did; did="$(sed -nE 's/.*\(ID: ([0-9]+)\).*/\1/p' <<<"$added")"
		[ -n "$did" ] || { echo "could not parse destination id: $added" >&2; return 1; }
		local before; before="$(wp "$src" --url="$su/" eval 'echo count(SiteGuard_Backup_History::get_history_raw());')"
		wp "$src" --url="$su/" siteguardbackup migrate_send "$sts" "$did" >/dev/null || { echo "migrate_send failed" >&2; return 1; }
		local after; after="$(wp "$src" --url="$su/" eval 'echo count(SiteGuard_Backup_History::get_history_raw());')"
		[ "$before" = "$after" ] || echo "SOURCE_HISTORY_GREW $before->$after" >&2
	fi
	newest_ts "$dst"
}

# verify <dst> <target-url> <label> <src-host> [expect-other-sites]
verify_target() {
	local d="$1" tu="$2" label="$3" shost="$4" others="${5:-}"
	local U=(--url="$tu/")
	local path; path="$(sed -E 's#^https?://[^/]+##' <<<"$tu")"
	check "siteurl stays $tu"                eq "$(wp "$d" "${U[@]}" option get siteurl)" "$tu"
	check "home stays $tu"                   eq "$(wp "$d" "${U[@]}" option get home)" "$tu"
	check "marker option = $label"           eq "$(wp "$d" "${U[@]}" option get sgx_marker)" "$label"
	check "serialized URLs rewritten and still valid" eq "$(wp "$d" "${U[@]}" eval '$v = get_option("sgx_ser"); echo is_array($v) ? $v["link"] . "|" . $v["n"]["img"] . "|" . $v["n"]["txt"] : "BROKEN";')" "$tu/deep/page/|$tu/x.png|héllo 🐍 100%"
	check "escaped JSON URL rewritten"         eq "$(wp "$d" "${U[@]}" eval '$v = json_decode(get_option("sgx_json"), true); echo is_array($v) ? $v["link"] : "BROKEN";')" "$tu/deep/page/"
	local pid; pid="$(wp "$d" "${U[@]}" post list --name="sgx-$label" --field=ID --post_type=post)"
	check "marker post present"              test -n "$pid"
	local content; content="$(wp "$d" "${U[@]}" post get "${pid:-0}" --field=post_content 2>/dev/null)"
	check "content links rewritten to target" grep -qF "$tu/sgx-$label/" <<<"$content"
	check "no source host left in content"   bash -c '! grep -qF "//$1" <<<"$2"' _ "$shost" "$content"
	local img; img="$(grep -oE 'src="[^"]+"' <<<"$content" | head -1 | sed -E 's/^src="|"$//g')"
	check "image URL on target host"         grep -qF "$tu/wp-content/uploads/" <<<"$img"
	check "image served (HTTP 200)"          eq "$(http "$d" "$(sed -E 's#^https?://[^/]+##' <<<"$img")")" 200
	# The source's permalink structure comes across with it, so ask WordPress.
	local link; link="$(wp "$d" "${U[@]}" eval "echo get_permalink(${pid:-0});" | sed -E 's#^https?://[^/]+##')"
	check "permalink is on the target"       grep -q "^$path/" <<<"$link"
	check "post page served (HTTP 200)"      eq "$(http "$d" "$link")" 200
	check "post page shows the marker"       grep -qF "SGX $label" <<<"$(http_body "$d" "$link")"
	check "theme twentytwentyfour active"    eq "$(wp "$d" "${U[@]}" option get stylesheet)" twentytwentyfour
	check "plugin sgx-marker active"         wp "$d" "${U[@]}" eval 'exit(is_plugin_active("sgx-marker/sgx-marker.php") ? 0 : 1);'
	check "roles defined on target"          test "$(wp "$d" "${U[@]}" role list --field=role | wc -l)" -ge 5
	check "an administrator can manage it"   wp "$d" "${U[@]}" eval '$a = get_users(array("role" => "administrator", "number" => 1)); if (!$a && is_multisite()) { $a = array(get_user_by("id", 1)); } exit($a && user_can($a[0], "manage_options") ? 0 : 1);'
	check "editor sgx$label carried with role" wp "$d" "${U[@]}" eval "\$u = get_user_by('login', 'sgx$label'); exit(\$u && user_can(\$u, 'edit_others_posts') ? 0 : 1);"
	check "marker post authored by sgx$label"  eq "$(wp "$d" "${U[@]}" eval "\$p = get_post($pid); \$u = \$p ? get_userdata(\$p->post_author) : null; echo \$u ? \$u->user_login : '-';")" "sgx$label"
	check "no source URLs in target options" eq "$(wp "$d" "${U[@]}" eval "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->options} WHERE option_value LIKE '%//$shost%'\");")" 0
	if [ -n "$others" ]; then
		check "destination main site intact"   eq "$(wp "$d" option get sgx_dst_marker)" main
		check "destination main site served"   eq "$(http "$d" /)" 200
		if [ "$others" = sub2 ]; then
			check "destination sub2 intact"      eq "$(wp "$d" --url="http://$d.test/sub2/" option get sgx_dst_marker)" sub2
			check "destination sub2 served"      eq "$(http "$d" /sub2/)" 200
			check "sub2's own SiteGuard settings kept" eq "$(wp "$d" --url="http://$d.test/sub2/" eval 'echo SiteGuard_Backup_Options::instance()->get_option("sgbackup_retain");')" 7
		fi
	fi
	check "SiteGuard still backs up on target" wp "$d" "${U[@]}" siteguardbackup backup
	check "no PHP fatal errors on target"    bash -c "! $(printf %q "$IM") compose $d exec -T cli sh -c 'grep -qE \"PHP (Fatal|Parse)\" wp-content/debug.log 2>/dev/null'"
}

mark_dst() { # stamp destination sites so we can prove they were left alone
	local d="$1" mode="$2"
	wp "$d" option update sgx_dst_marker main >/dev/null
	if [ "$mode" = multisite ]; then
		wp "$d" --url="http://$d.test/sub1/" option update sgx_dst_marker sub1 >/dev/null
		wp "$d" --url="http://$d.test/sub2/" option update sgx_dst_marker sub2 >/dev/null
		# sub2's own SiteGuard settings must survive restores aimed at other sites.
		wp "$d" --url="http://$d.test/sub2/" eval 'SiteGuard_Backup_Options::instance()->update_option("sgbackup_retain", 7);' >/dev/null
	fi
}

run_scenario() { # scenario method
	local s="$1" m="$2" scope dmode restore_args=() target others="" label
	case "$s" in
		s2s)  scope=single;  dmode=single ;;
		s2mn) scope=single;  dmode=multisite ;;
		s2me) scope=single;  dmode=multisite ;;
		m2s)  scope=sub1;    dmode=single ;;
		mm2s) scope=main;    dmode=single ;;
		m2mn) scope=sub1;    dmode=multisite ;;
		mm2m) scope=network; dmode=multisite ;;
	esac
	backup_scope "$scope"
	local D="xt-dst-${s}-${m}"
	CTX="$s/$m"
	local fail_before=$FAIL
	echo "== $s via $m ($SRC ${SRC_URL#http://} -> $D, $dmode)"
	create "$D" "$dmode" || { bad "destination create"; return; }
	mark_dst "$D" "$dmode"
	local dts; dts="$(transport "$m" "$SRC" "$SRC_URL" "$D" 2>/tmp/xt.err)"; local terr; terr="$(cat /tmp/xt.err)"
	grep -q SOURCE_HISTORY_GREW <<<"$terr" && bad "remote send added entries to the source history ($terr)"
	if [ -z "$dts" ]; then bad "transport failed: $terr"; [ $KEEP = 1 ] || "$IM" destroy "$D" --yes >/dev/null 2>&1; return; fi
	ok "transported (destination set $dts)"

	case "$s" in
		s2s|m2s)      target="http://$D.test"; restore_args=(--db-restore-mode=migrate) ;;
		mm2s)         target="http://$D.test"; restore_args=(--subsite-mode=main-only --db-restore-mode=migrate) ;;
		s2mn|m2mn)    target="http://$D.test/imported"; others=sub2
		              restore_args=(--subsite-mode=import-as "--target-subsite-domain=$D.test/imported/" --db-restore-mode=migrate) ;;
		s2me)         target="http://$D.test/sub1"; others=sub2
		              restore_args=(--subsite-mode=import-as --target-subsite-id=2 --db-restore-mode=migrate) ;;
		mm2m)         target="http://$D.test"; restore_args=(--db-restore-mode=migrate) ;;
	esac
	if ! wp "$D" siteguardbackup restore "$dts" "${restore_args[@]}" --yes > /tmp/xt.restore 2>&1; then
		bad "restore command failed: $(tail -3 /tmp/xt.restore | tr '\n' ' ')"
	else
		ok "restore command succeeded"
	fi
	verify_target "$D" "$target" "$SRC_LABEL" "$SRC.test" "$others"
	if [ "$s" = mm2m ]; then
		echo "  -- whole network: sub1 must also have come across"
		verify_target "$D" "http://$D.test/sub1" sub1 "$SRC_M.test"
	fi
	if [ $KEEP = 1 ] || { [ $KEEP_FAILED = 1 ] && [ $FAIL -gt $fail_before ]; }; then
		echo "  (kept $D for inspection)"
	else
		"$IM" destroy "$D" --yes >/dev/null 2>&1
	fi
}

# ---------------------------------------------------------------------------
echo "== preparing sources"
create "$SRC_S" single || exit 1
create "$SRC_M" multisite || exit 1
seed "$SRC_S" "http://$SRC_S.test" single
seed "$SRC_M" "http://$SRC_M.test" main
seed "$SRC_M" "http://$SRC_M.test/sub1" sub1
wp "$SRC_S" siteguardbackup backup >/dev/null || { echo "source backup failed" >&2; exit 1; }
wp "$SRC_M" --url="http://$SRC_M.test/sub1/" siteguardbackup backup --subsite-only >/dev/null || { echo "sub1 backup failed" >&2; exit 1; }

for s in "${SCEN[@]}"; do
	for m in "${METHODS[@]}"; do
		case "$s" in
			mm2s) wp "$SRC_M" siteguardbackup backup >/dev/null ;;
			mm2m) wp "$SRC_M" siteguardbackup backup >/dev/null ;;
		esac
		run_scenario "$s" "$m"
	done
done

[ $KEEP = 1 ] || { "$IM" destroy "$SRC_S" --yes >/dev/null 2>&1; "$IM" destroy "$SRC_M" --yes >/dev/null 2>&1; }
echo
echo "$PASS passed, $FAIL failed"
for f in "${FAILED[@]}"; do echo "  - $f"; done
[ "$FAIL" -eq 0 ]
