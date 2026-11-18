#!/usr/bin/env bash
# =============================================================================
# Remote storage tests against local emulators on the isolated instance mesh.
#
#   tests/integration/storage.sh <instance-manager> <instance> [provider...]
#
# Providers (default: all): s3 azure webdav s3generic b2 gcs sftp scp sftpkey sftppaste sftped25519
#   ftp ftps ftpsi ftpext ftpsverify openstack openstack2 email dropbox googledrive onedrive pcloud
#   pcloudeu oauthflow jobs
#
# Starts a server or emulator per provider as a container on the internal
# "wpi-mesh" network, then runs storage-test.php inside <instance> for each:
# credentials test, small and multipart/chunked uploads, listing (exactly the
# uploaded files), byte-identical downloads, unsafe-name delete guard, delete,
# and a failed download leaving nothing behind.
#
#   real servers:  versitygw (S3, also as B2 / GCS / generic over TLS),
#                  Azurite, rclone WebDAV, OpenSSH, ProFTPD (mod_tls), Swift SAIO,
#                  Mailpit
#   protocol fakes (fake-clouds.py, written from each provider's API docs):
#                  Dropbox, Google Drive, OneDrive (Graph), pCloud, Keystone
#
# TLS endpoints use a throwaway CA trusted by a test-only mu-plugin. Real host
# names (storage.googleapis.com, graph.microsoft.com, ...) are network aliases
# on wpi-mesh, which has no internet route. Everything is removed afterwards.
# =============================================================================
set -euo pipefail

IM="${1:?instance manager required}"
INST="${2:?instance name required}"
shift 2
WANT=("$@"); [ ${#WANT[@]} -gt 0 ] || WANT=(s3 azure webdav s3generic b2 gcs sftp scp sftpkey sftppaste sftped25519 ftp ftps ftpsi ftpext ftpsverify openstack openstack2 email dropbox googledrive onedrive pcloud pcloudeu oauthflow jobs)
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ "$INST" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || { echo "bad instance name" >&2; exit 2; }

WORK="$(mktemp -d /tmp/sg-storage.XXXXXX)"
cleanup() {
	docker rm -f sgt-s3 sgt-azurite sgt-webdav sgt-s3tls-gcs sgt-s3tls-b2 sgt-s3tls-gen sgt-ssh sgt-ftp sgt-ftpi sgt-swift sgt-mail sgt-fakes >/dev/null 2>&1 || true
	"$IM" compose "$INST" exec -T cli sh -c 'rm -rf wp-content/mu-plugins/sg-test-ca.php wp-content/sg-test-ca.pem wp-content/mu-plugins/sg-test-bundle.php wp-content/sg-test-bundle.pem wp-content/mu-plugins/sg-test-mail.php wp-content/mu-plugins/sg-test-ftpext.php wp-content/sg-test-keys wp-content/storage-test.php wp-content/oauth-flow.php wp-content/job-test.php' >/dev/null 2>&1 || true
	rm -rf --one-file-system -- "$WORK"
}
trap cleanup EXIT

wpc() { "$IM" wp "$INST" "$@" 2> >(grep -v -e wp_update -e 'Warning: .*WordPress.org' >&2); }
wp_c() { "$IM" compose "$INST" ps -q wordpress; }

# The test script runs from inside the instance.
docker cp -q "$HERE/storage-test.php" "$(wp_c):/var/www/html/wp-content/storage-test.php"
docker exec -u 0 "$(wp_c)" chown 33:33 /var/www/html/wp-content/storage-test.php


# --- shared helpers for the TLS-fronted providers -----------------------------
MESH_HOSTS="ftp.test ftpi.test storage.googleapis.com s3.us-west-004.backblazeb2.com s3g.test api.dropboxapi.com content.dropboxapi.com oauth2.googleapis.com www.googleapis.com login.microsoftonline.com graph.microsoft.com fake-my.sharepoint.com api.pcloud.com eapi.pcloud.com p-fake1.pcloud.com keystone.test accounts.google.com www.dropbox.com my.pcloud.com"
TLS_READY=0
tls_setup() {
	[ "$TLS_READY" = 1 ] && return 0
	mkdir -p "$WORK/tls"
	openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=SG Test Bundle CA" \
		-keyout "$WORK/tls/ca.key" -out "$WORK/tls/ca.pem" >/dev/null 2>&1
	openssl req -newkey rsa:2048 -nodes -subj "/CN=sg-test" \
		-keyout "$WORK/tls/key.pem" -out "$WORK/tls/req.csr" >/dev/null 2>&1
	printf 'subjectAltName=%s\n' "$(printf 'DNS:%s,' $MESH_HOSTS | sed 's/,$//')" > "$WORK/tls/san.ext"
	openssl x509 -req -in "$WORK/tls/req.csr" -CA "$WORK/tls/ca.pem" -CAkey "$WORK/tls/ca.key" -CAcreateserial \
		-days 1 -extfile "$WORK/tls/san.ext" -out "$WORK/tls/cert.pem" >/dev/null 2>&1
	chmod 644 "$WORK"/tls/*.pem
	# Trust the throwaway CA (on top of WordPress' own bundle) for this instance only.
	docker exec "$(wp_c)" cat /var/www/html/wp-includes/certificates/ca-bundle.crt > "$WORK/tls/bundle.pem"
	cat "$WORK/tls/ca.pem" >> "$WORK/tls/bundle.pem"
	docker cp -q "$WORK/tls/bundle.pem" "$(wp_c):/var/www/html/wp-content/sg-test-bundle.pem"
	"$IM" compose "$INST" exec -T cli sh -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/sg-test-bundle.php' <<'PHP'
<?php
add_filter('http_request_args', function ($args) {
	$args['sslcertificates'] = WP_CONTENT_DIR . '/sg-test-bundle.pem';
	return $args;
}, 20);
PHP
	TLS_READY=1
}

# versitygw over TLS, published under real provider host names.
s3tls() { # <container> <alias> <region> <key> <secret>
	docker rm -f "$1" >/dev/null 2>&1 || true
	docker run -d --name "$1" --network wpi-mesh --network-alias "$2" -v "$WORK/tls:/certs:ro" \
		-e ROOT_ACCESS_KEY="$4" -e ROOT_SECRET_KEY="$5" \
		--entrypoint sh versity/versitygw:latest -c "mkdir -p /data && exec versitygw --port :443 --cert /certs/cert.pem --key /certs/key.pem --region $3 posix /data" >/dev/null
	for _ in $(seq 1 30); do docker exec "$1" sh -c 'nc -z 127.0.0.1 443' 2>/dev/null && break; sleep 1; done
	sleep 1
}

FAKES_READY=0
fakes_setup() {
	[ "$FAKES_READY" = 1 ] && return 0
	tls_setup
	local aliases=()
	for h in api.dropboxapi.com content.dropboxapi.com oauth2.googleapis.com www.googleapis.com login.microsoftonline.com graph.microsoft.com fake-my.sharepoint.com api.pcloud.com eapi.pcloud.com p-fake1.pcloud.com keystone.test accounts.google.com www.dropbox.com my.pcloud.com; do aliases+=(--network-alias "$h"); done
	# Pretty permalinks give the query-free OAuth redirect URI; register it with the fake apps.
	wpc rewrite structure '/%postname%/' >/dev/null 2>&1 || true
	"$IM" compose "$INST" exec -T cli sh -c 'cat > wp-content/oauth-flow.php' < "$HERE/oauth-flow.php"
	REDIRECT_URI=$(wpc eval-file wp-content/oauth-flow.php redirect 2>/dev/null | tail -1)
	docker rm -f sgt-fakes >/dev/null 2>&1 || true
	docker run -d --name sgt-fakes --network wpi-mesh "${aliases[@]}" \
		-v "$WORK/tls:/certs:ro" -v "$HERE/fake-clouds.py:/fake-clouds.py:ro" \
		python:3.12-alpine python3 -u /fake-clouds.py /certs/cert.pem /certs/key.pem --swift http://swift.test:8080 --redirect "$REDIRECT_URI" >/dev/null
	for _ in $(seq 1 30); do docker logs sgt-fakes 2>&1 | grep -q listening && break; sleep 1; done
	FAKES_READY=1
}

# One throwaway curl on the mesh (the WordPress container has no curl).
mesh_curl() { docker run --rm --network wpi-mesh curlimages/curl:8.11.1 -sS "$@"; }

run_test() { # <method> <json>
	wpc eval-file wp-content/storage-test.php "$1" "$2" || rc=1
}

rc=0
for want in "${WANT[@]}"; do
	case "$want" in
	s3)
		echo "== S3 (versitygw, path-style over http with allow_insecure)"
		KEY=sgtest$(openssl rand -hex 4); SECRET=$(openssl rand -hex 16)
		docker rm -f sgt-s3 >/dev/null 2>&1 || true
		# MinIO images are no longer published; versitygw is an S3 gateway over a local dir.
		docker run -d --name sgt-s3 --network wpi-mesh --network-alias s3.test \
			-e ROOT_ACCESS_KEY="$KEY" -e ROOT_SECRET_KEY="$SECRET" \
			--entrypoint sh versity/versitygw:latest -c 'mkdir -p /data && exec versitygw --port :9000 posix /data' >/dev/null
		for _ in $(seq 1 60); do docker logs sgt-s3 2>&1 | grep -qi "listen\|start\|9000" && break; sleep 1; done
		sleep 2
		OPTS=$(printf '{"__create":true,"provider":"minio","endpoint":"http://s3.test:9000","allow_insecure":true,"region":"us-east-1","bucket":"sgtest-bucket","path":"sites/a","access_key":"%s","secret_key":"%s"}' "$KEY" "$SECRET")
		wpc eval-file wp-content/storage-test.php s3 "$OPTS" || rc=1
		;;
	azure)
		echo "== Azure Blob (Azurite, production-style host over TLS)"
		ACCT=sgacct
		openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=SG Test CA" \
			-keyout "$WORK/ca.key" -out "$WORK/ca.pem" >/dev/null 2>&1
		openssl req -newkey rsa:2048 -nodes -subj "/CN=$ACCT.azurite.test" \
			-keyout "$WORK/key.pem" -out "$WORK/req.csr" >/dev/null 2>&1
		printf 'subjectAltName=DNS:%s.azurite.test\n' "$ACCT" > "$WORK/san.ext"
		openssl x509 -req -in "$WORK/req.csr" -CA "$WORK/ca.pem" -CAkey "$WORK/ca.key" -CAcreateserial \
			-days 1 -extfile "$WORK/san.ext" -out "$WORK/cert.pem" >/dev/null 2>&1
		chmod 644 "$WORK"/*.pem
		AKEY=$(openssl rand -base64 64 | tr -d '\n')
		docker rm -f sgt-azurite >/dev/null 2>&1 || true
		docker run -d --name sgt-azurite --network wpi-mesh --network-alias "$ACCT.azurite.test" \
			-v "$WORK:/certs:ro" -e AZURITE_ACCOUNTS="$ACCT:$AKEY" \
			mcr.microsoft.com/azure-storage/azurite \
			azurite-blob --blobHost 0.0.0.0 --blobPort 443 --cert /certs/cert.pem --key /certs/key.pem \
			--skipApiVersionCheck --loose >/dev/null
		for _ in $(seq 1 60); do docker logs sgt-azurite 2>&1 | grep -q "successfully listens" && break; sleep 1; done
		# Trust the throwaway CA for this host only, test-only mu-plugin.
		docker cp -q "$WORK/ca.pem" "$(wp_c):/var/www/html/wp-content/sg-test-ca.pem"
		"$IM" compose "$INST" exec -T cli sh -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/sg-test-ca.php' <<'PHP'
<?php
add_filter('http_request_args', function ($args, $url) {
	if (false !== strpos((string) wp_parse_url($url, PHP_URL_HOST), '.azurite.test')) {
		$args['sslcertificates'] = WP_CONTENT_DIR . '/sg-test-ca.pem';
	}
	return $args;
}, 10, 2);
PHP
		OPTS=$(printf '{"__create":true,"account_name":"%s","account_key":"%s","container":"sgtest","path":"sites/a","endpoint_suffix":"azurite.test"}' "$ACCT" "$AKEY")
		wpc eval-file wp-content/storage-test.php azure "$OPTS" || rc=1
		;;
	webdav)
		echo "== WebDAV (rclone serve webdav, over http: must be flagged)"
		WU=sgtest; WP=$(openssl rand -hex 12)
		docker rm -f sgt-webdav >/dev/null 2>&1 || true
		docker run -d --name sgt-webdav --network wpi-mesh --network-alias webdav.test \
			rclone/rclone:latest serve webdav /data --addr :80 --user "$WU" --pass "$WP" >/dev/null
		for _ in $(seq 1 30); do docker logs sgt-webdav 2>&1 | grep -qi "serving on\|webdav server at" && break; sleep 1; done
		docker exec sgt-webdav mkdir -p /data/backups
		OPTS=$(printf '{"url":"http://webdav.test","user":"%s","pass":"%s","path":"/backups"}' "$WU" "$WP")
		wpc eval-file wp-content/storage-test.php webdav "$OPTS" || rc=1
		check_outside=$(docker exec sgt-webdav sh -c 'ls -A /data' | tr '\n' ' ')
		if [ "$check_outside" = "backups " ]; then echo "  ok   webdav: backup folder itself still exists after the unsafe-name delete"; else echo "  FAIL webdav: /data now holds: $check_outside"; rc=1; fi
		;;
	s3generic)
		echo "== S3-compatible generic (versitygw over TLS, s3g.test)"
		tls_setup
		KEY=sgtest$(openssl rand -hex 4); SECRET=$(openssl rand -hex 16)
		s3tls sgt-s3tls-gen s3g.test us-east-1 "$KEY" "$SECRET"
		run_test s3generic "$(printf '{"__create":true,"endpoint":"https://s3g.test","region":"us-east-1","bucket":"sgtest-generic","path":"sites/g","access_key":"%s","secret_key":"%s"}' "$KEY" "$SECRET")"
		;;
	b2)
		echo "== Backblaze B2 (S3 API: versitygw as s3.us-west-004.backblazeb2.com)"
		tls_setup
		KEY=004sg$(openssl rand -hex 6); SECRET=$(openssl rand -hex 16)
		s3tls sgt-s3tls-b2 s3.us-west-004.backblazeb2.com us-west-004 "$KEY" "$SECRET"
		run_test b2 "$(printf '{"__create":true,"region":"us-west-004","bucket":"sgtest-b2","path":"sites/b","access_key":"%s","secret_key":"%s"}' "$KEY" "$SECRET")"
		;;
	gcs)
		echo "== Google Cloud Storage (XML/S3 interop: versitygw as storage.googleapis.com, region auto)"
		tls_setup
		KEY=GOOG1E$(openssl rand -hex 8 | tr a-f A-F); SECRET=$(openssl rand -hex 20)
		s3tls sgt-s3tls-gcs storage.googleapis.com auto "$KEY" "$SECRET"
		run_test gcs "$(printf '{"__create":true,"bucket":"sgtest-gcs","path":"sites/c","access_key":"%s","secret_key":"%s"}' "$KEY" "$SECRET")"
		;;
	sftp|scp|sftpkey|sftppaste|sftped25519)
		echo "== $want (OpenSSH server)"
		if ! docker ps -q -f name='^sgt-ssh$' | grep -q .; then
			SSHPASS=$(openssl rand -hex 12)
			docker run -d --name sgt-ssh --network wpi-mesh --network-alias ssh.test \
				-e PUID=1000 -e PGID=1000 -e USER_NAME=sguser -e PASSWORD_ACCESS=true -e USER_PASSWORD="$SSHPASS" \
				lscr.io/linuxserver/openssh-server:latest >/dev/null
			for _ in $(seq 1 60); do docker exec sgt-ssh sh -c 'nc -z 127.0.0.1 2222' 2>/dev/null && break; sleep 1; done
			sleep 3
			docker exec sgt-ssh sh -c 'mkdir -p /config/backups && chown 1000:1000 /config/backups'
			# RSA key for the key-auth run, placed where the plugin allows key paths.
			ssh-keygen -q -t rsa -b 3072 -m PEM -N '' -f "$WORK/sgkey" >/dev/null
			# The settings page asks for the key itself: an OpenSSH-format RSA key with a
			# passphrase, and an Ed25519 key (unsupported: must fail with a clear message).
			ssh-keygen -q -t rsa -b 3072 -N 'kp-'"$SSHPASS" -f "$WORK/sgkey2" >/dev/null
			ssh-keygen -q -t ed25519 -N '' -f "$WORK/sgkey3" >/dev/null
			cat "$WORK/sgkey2.pub" "$WORK/sgkey3.pub" >> "$WORK/sgkey.pub.all"; cat "$WORK/sgkey.pub" >> "$WORK/sgkey.pub.all"
			docker exec -i sgt-ssh sh -c 'mkdir -p /config/.ssh && cat >> /config/.ssh/authorized_keys && chown -R 1000:1000 /config/.ssh && chmod 700 /config/.ssh && chmod 600 /config/.ssh/authorized_keys' < "$WORK/sgkey.pub.all"
			docker exec -u 0 "$(wp_c)" mkdir -p /var/www/html/wp-content/sg-test-keys
			docker cp -q "$WORK/sgkey" "$(wp_c):/var/www/html/wp-content/sg-test-keys/sgkey"
			docker cp -q "$WORK/sgkey.pub" "$(wp_c):/var/www/html/wp-content/sg-test-keys/sgkey.pub"
			docker exec -u 0 "$(wp_c)" sh -c 'chown -R 33:33 /var/www/html/wp-content/sg-test-keys && chmod 600 /var/www/html/wp-content/sg-test-keys/sgkey'
		fi
		case "$want" in
		sftp)    run_test sftp "$(printf '{"host":"ssh.test","port":2222,"user":"sguser","pass":"%s","path":"/config/backups","protocol":"sftp"}' "$SSHPASS")" ;;
		scp)     run_test sftp "$(printf '{"host":"ssh.test","port":2222,"user":"sguser","pass":"%s","path":"/config/backups","protocol":"scp"}' "$SSHPASS")" ;;
		sftpkey) run_test sftp '{"host":"ssh.test","port":2222,"user":"sguser","key":"/var/www/html/wp-content/sg-test-keys/sgkey","path":"/config/backups","protocol":"sftp"}' ;;
		sftppaste)
			echo "  -- pasted OpenSSH-format RSA key with passphrase"
			run_test sftp "$(python3 -c 'import json,sys; print(json.dumps({"host":"ssh.test","port":2222,"user":"sguser","key":open(sys.argv[1]).read(),"pass":sys.argv[2],"path":"/config/backups","protocol":"sftp"}))' "$WORK/sgkey2" "kp-$SSHPASS")" ;;
		sftped25519)
			OUT=$(wpc eval-file wp-content/storage-test.php sftp "$(python3 -c 'import json,sys; print(json.dumps({"host":"ssh.test","port":2222,"user":"sguser","key":open(sys.argv[1]).read(),"path":"/config/backups","protocol":"sftp"}))' "$WORK/sgkey3")" 2>&1 || true)
			if echo "$OUT" | grep -q 'credentials test (sftp_invalid_key: .*Ed25519'; then echo "  ok   sftp: unsupported Ed25519 key reported clearly"; else echo "  FAIL sftp: Ed25519 key not reported clearly"; echo "$OUT" | head -3; rc=1; fi ;;
		esac
		;;
	ftp|ftps|ftpsi|ftpext|ftpsverify)
		echo "== $want (ProFTPD)"
		tls_setup
		# WP-CLI (which runs the tests) lives in the "cli" container (Alpine).
		CLI_C=$("$IM" compose "$INST" ps -q cli)
		if [ "$want" = ftpext ] && ! docker exec "$CLI_C" php -r 'exit(function_exists("ftp_connect") ? 0 : 1);'; then
			echo "  -- PHP has no ftp extension: with curl disabled the module must refuse cleanly, not fatal"
			"$IM" compose "$INST" exec -T cli sh -c 'cat > wp-content/mu-plugins/sg-test-ftpext.php' <<'PHP'
<?php add_filter('sgbackup_ftp_use_curl', '__return_false');
PHP
			NOEXT=$(wpc eval-file wp-content/storage-test.php ftp '{"host":"ftp.test","port":21,"user":"x","pass":"y","path":"/"}' 2>&1 || true)
			if echo "$NOEXT" | grep -q 'credentials test (ftp_unavailable' && ! echo "$NOEXT" | grep -qi 'fatal'; then
				echo "  ok   ftp: missing ext-ftp is reported as ftp_unavailable"
			else
				echo "  FAIL ftp: missing ext-ftp not handled cleanly"; echo "$NOEXT" | head -5; rc=1
			fi
			echo "  -- building ext-ftp (with OpenSSL) in a throwaway copy of the cli image, then loading it"
			# The instance network has no internet, so build next door and copy the module in.
			CLI_IMG=$(docker inspect --format '{{.Config.Image}}' "$CLI_C")
			mkdir -p "$WORK/ext"
			docker run --rm -u 0 -v "$WORK/ext:/out" --entrypoint sh "$CLI_IMG" -c \
				'apk add --no-cache $PHPIZE_DEPS openssl-dev >/dev/null && docker-php-ext-configure ftp --with-openssl-dir=/usr >/dev/null && docker-php-ext-install -j4 ftp >/dev/null 2>&1 && cp "$(php-config --extension-dir)/ftp.so" /out/ && cp /usr/local/etc/php/conf.d/docker-php-ext-ftp.ini /out/' \
				|| { echo "  FAIL could not build the ftp extension"; rc=1; continue; }
			EXTDIR=$(docker exec "$CLI_C" php-config --extension-dir)
			docker cp -q "$WORK/ext/ftp.so" "$CLI_C:$EXTDIR/ftp.so"
			docker cp -q "$WORK/ext/docker-php-ext-ftp.ini" "$CLI_C:/usr/local/etc/php/conf.d/docker-php-ext-ftp.ini"
		fi
		if ! docker image inspect sgt-proftpd:test >/dev/null 2>&1; then
			printf 'FROM alpine:3.20\nRUN apk add --no-cache proftpd proftpd-mod_tls\n' | docker build -q -t sgt-proftpd:test - >/dev/null
		fi
		if ! docker ps -q -f name='^sgt-ftp$' | grep -q .; then
			docker rm -f sgt-ftp sgt-ftpi >/dev/null 2>&1 || true
			FTPPASS=$(openssl rand -hex 12)
			# ProFTPD's mod_tls requires TLS session reuse on data connections by
			# default (like vsftpd and FileZilla Server); PHP's ext-ftp cannot do that.
			for v in ftp ftpi; do
				if [ $v = ftp ]; then IP=10.212.250.21; PORT=21; IMPL=''; REQ=off; else IP=10.212.250.22; PORT=990; IMPL='TLSOptions UseImplicitSSL'; REQ=on; fi
				cat > "$WORK/proftpd-$v.conf" <<CONF
ServerName "sgtest"
ServerType standalone
DefaultServer on
Port $PORT
User nobody
Group nogroup
DefaultRoot ~
RequireValidShell off
PassivePorts 30000 30019
MasqueradeAddress $IP
UseIPv6 off
ScoreboardFile /run/proftpd/proftpd.scoreboard
DelayTable none
<IfModule mod_ctrls.c>
ControlsEngine off
</IfModule>
LoadModule mod_tls.c
<IfModule mod_tls.c>
TLSEngine on
TLSRequired $REQ
TLSProtocol TLSv1.2 TLSv1.3
TLSRSACertificateFile /certs/cert.pem
TLSRSACertificateKeyFile /certs/key.pem
TLSVerifyClient off
$IMPL
</IfModule>
<Directory />
AllowOverwrite on
</Directory>
CONF
				docker run -d --name "sgt-$v" --network wpi-mesh --ip "$IP" --network-alias "$v.test" \
					-v "$WORK/tls:/certs:ro" -v "$WORK/proftpd-$v.conf:/etc/proftpd/proftpd.conf:ro" --entrypoint sh sgt-proftpd:test -c \
					"mkdir -p /run/proftpd && adduser -D -h /home/sguser sguser && echo 'sguser:$FTPPASS' | chpasswd && mkdir -p /home/sguser/backups && chown sguser /home/sguser/backups && exec proftpd -n -c /etc/proftpd/proftpd.conf" >/dev/null
			done
			sleep 3
		fi
		case "$want" in
		ftp)    run_test ftp "$(printf '{"host":"ftp.test","port":21,"user":"sguser","pass":"%s","path":"/backups","passive":true,"ftps_mode":"none"}' "$FTPPASS")" ;;
		ftps)   run_test ftp "$(printf '{"host":"ftp.test","port":21,"user":"sguser","pass":"%s","path":"/backups","passive":true,"ftps_mode":"explicit"}' "$FTPPASS")" ;;
		ftpsi)  run_test ftp "$(printf '{"host":"ftpi.test","port":990,"user":"sguser","pass":"%s","path":"/backups","passive":true,"ftps_mode":"implicit"}' "$FTPPASS")" ;;
		ftpext)
			echo "  -- ext-ftp fallback (curl disabled by filter), plain FTP"
			run_test ftp "$(printf '{"host":"ftp.test","port":21,"user":"sguser","pass":"%s","path":"/backups","passive":true,"ftps_mode":"none"}' "$FTPPASS")"
			docker exec -u 0 "$(wp_c)" rm -f /var/www/html/wp-content/mu-plugins/sg-test-ftpext.php ;;
		ftpsverify)
			# Without the test CA the server certificate is untrusted: FTPS must refuse.
			docker exec -u 0 "$(wp_c)" mv /var/www/html/wp-content/mu-plugins/sg-test-bundle.php /var/www/html/wp-content/sg-test-bundle.off
			OUT=$(wpc eval-file wp-content/storage-test.php ftp "$(printf '{"host":"ftp.test","port":21,"user":"sguser","pass":"%s","path":"/backups","passive":true,"ftps_mode":"explicit"}' "$FTPPASS")" 2>&1 || true)
			docker exec -u 0 "$(wp_c)" mv /var/www/html/wp-content/sg-test-bundle.off /var/www/html/wp-content/mu-plugins/sg-test-bundle.php
			if echo "$OUT" | grep -q 'FAIL ftp: credentials test (ftp_failed: .*\(certificate\|SSL\)'; then
				echo "  ok   ftps: untrusted server certificate is refused"
				echo "$OUT" | grep -m1 'credentials test' | cut -c1-200 | sed 's/^/       /'
			else
				echo "  FAIL ftps: untrusted certificate was not refused"; echo "$OUT" | head -3; rc=1
			fi ;;
		esac
		;;
	openstack|openstack2)
		echo "== $want (Swift SAIO behind Keystone $( [ "$want" = openstack ] && echo v3 || echo v2.0 ))"
		fakes_setup
		if ! docker ps -q -f name='^sgt-swift$' | grep -q .; then
			docker run -d --name sgt-swift --network wpi-mesh --network-alias swift.test openstackswift/saio:latest >/dev/null
			for _ in $(seq 1 90); do mesh_curl -o /dev/null -w '%{http_code}' http://swift.test:8080/healthcheck 2>/dev/null | grep -q 200 && break; sleep 2; done
			TOK=$(mesh_curl -i -H 'X-Storage-User: test:tester' -H 'X-Storage-Pass: testing' http://swift.test:8080/auth/v1.0 | awk -F': ' 'tolower($1)=="x-auth-token"{print $2}' | tr -d '\r')
			mesh_curl -o /dev/null -w '  container create: HTTP %{http_code}\n' -X PUT -H "X-Auth-Token: $TOK" http://swift.test:8080/v1/AUTH_test/sgtest-swift
		fi
		if [ "$want" = openstack ]; then
			run_test openstack '{"auth_url":"https://keystone.test/v3","auth_version":"v3","username":"sguser","password":"sgpass","tenant":"sgproject","region":"RegionOne","container":"sgtest-swift","path":"sites/o"}'
		else
			run_test openstack '{"auth_url":"https://keystone.test/v2.0","auth_version":"v2","username":"sguser","password":"sgpass","tenant":"sgproject","region":"RegionOne","container":"sgtest-swift","path":"sites/o2"}'
		fi
		# The module hides SLO segments from its listing; make sure delete removed them too.
		TOK=$(mesh_curl -i -H 'X-Storage-User: test:tester' -H 'X-Storage-Pass: testing' http://swift.test:8080/auth/v1.0 | awk -F': ' 'tolower($1)=="x-auth-token"{print $2}' | tr -d '\r')
		LEFT=$(mesh_curl -H "X-Auth-Token: $TOK" "http://swift.test:8080/v1/AUTH_test/sgtest-swift" | grep -c . || true)
		if [ "$LEFT" = 0 ]; then echo "  ok   $want: container empty after delete (no orphaned SLO segments)"; else echo "  FAIL $want: $LEFT objects left in the container"; rc=1; fi
		;;
	email)
		echo "== Email (wp_mail through Mailpit)"
		docker rm -f sgt-mail >/dev/null 2>&1 || true
		docker run -d --name sgt-mail --network wpi-mesh --network-alias mail.test -e MP_MAX_MESSAGES=50 -e MP_SMTP_AUTH_ACCEPT_ANY=1 -e MP_SMTP_AUTH_ALLOW_INSECURE=1 axllent/mailpit:latest >/dev/null
		for _ in $(seq 1 30); do mesh_curl -o /dev/null -w '%{http_code}' http://mail.test:8025/api/v1/info 2>/dev/null | grep -q 200 && break; sleep 1; done
		"$IM" compose "$INST" exec -T cli sh -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/sg-test-mail.php' <<'PHP'
<?php
add_action('phpmailer_init', function ($m) {
	$m->isSMTP();
	$m->Host = 'mail.test';
	$m->Port = 1025;
	$m->SMTPAuth = false;
	$m->SMTPAutoTLS = false;
});
PHP
		OUT=$(wpc eval-file wp-content/storage-test.php email '{"address":"backups@example.test"}' 2>&1) || rc=1
		echo "$OUT" | grep -v 'info sent-md5'
		SENT=$(echo "$OUT" | awk '/info sent-md5/{print $3}' | head -1)
		sleep 2
		MSGS=$(mesh_curl http://mail.test:8025/api/v1/messages)
		ID=$(echo "$MSGS" | python3 -c 'import json,sys; m=[x for x in json.load(sys.stdin)["messages"] if x.get("Attachments")]; print(m[0]["ID"] if m else "")')
		if [ -n "$ID" ]; then
			PART=$(mesh_curl "http://mail.test:8025/api/v1/message/$ID" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["Attachments"][0]["PartID"], d["Attachments"][0]["FileName"], d["To"][0]["Address"])')
			set -- $PART
			GOT=$(mesh_curl "http://mail.test:8025/api/v1/message/$ID/part/$1" | md5sum | cut -d' ' -f1)
			if [ "$GOT" = "$SENT" ] && [ "$3" = "backups@example.test" ]; then echo "  ok   email: attachment $2 arrived byte-identical at $3"; else echo "  FAIL email: attachment md5 $GOT != $SENT (to $3)"; rc=1; fi
			N=$(echo "$MSGS" | python3 -c 'import json,sys; print(sum(1 for x in json.load(sys.stdin)["messages"] if x.get("Attachments")))')
			[ "$N" = 1 ] && echo "  ok   email: oversized archive was not mailed" || { echo "  FAIL email: $N messages with attachments"; rc=1; }
		else
			echo "  FAIL email: no message with an attachment reached Mailpit"; rc=1
		fi
		docker exec -u 0 "$(wp_c)" rm -f /var/www/html/wp-content/mu-plugins/sg-test-mail.php
		;;
	dropbox)
		echo "== Dropbox (API fake)"
		fakes_setup
		run_test dropbox '{"app_key":"sg-dbx-key","app_secret":"sg-dbx-secret","folder":"/SiteGuard Backups","token":{"access_token":"expired-token","refresh_token":"rt-dropbox","expires_in":14400,"obtained_at":1}}'
		;;
	googledrive)
		echo "== Google Drive (API fake)"
		fakes_setup
		run_test googledrive '{"client_id":"sg-gd-client.apps.googleusercontent.com","client_secret":"sg-gd-secret","folder_name":"SiteGuard Backups","token":{"access_token":"expired-token","refresh_token":"rt-google","expires_in":3599,"obtained_at":1}}'
		;;
	onedrive)
		echo "== OneDrive (Microsoft Graph fake)"
		fakes_setup
		run_test onedrive '{"client_id":"11111111-2222-3333-4444-555555555555","client_secret":"sg-od-secret","folder":"SiteGuard Backups","token":{"access_token":"expired-token","refresh_token":"rt-microsoft","expires_in":3599,"obtained_at":1}}'
		;;
	pcloud|pcloudeu)
		echo "== $want (API fake)"
		fakes_setup
		if [ "$want" = pcloud ]; then
			run_test pcloud '{"access_token":"pc-token-us","folder_id":0,"use_eu":false}'
		else
			run_test pcloud '{"access_token":"pc-token-eu","folder_id":0,"use_eu":true}'
		fi
		;;
	oauthflow)
		echo "== OAuth sign-in, end to end (settings button -> provider -> REST callback), API fakes"
		fakes_setup
		echo "  -- redirect URI: $REDIRECT_URI"
		case "$REDIRECT_URI" in *\?*) echo "  FAIL redirect URI has a query string"; rc=1 ;; *) echo "  ok   redirect URI has no query string" ;; esac
		# The "browser": curl on the mesh, trusting the test CA for the provider pages.
		browser() { docker run --rm --network wpi-mesh -v "$WORK/tls/ca.pem:/ca.pem:ro" curlimages/curl:8.11.1 -sS --cacert /ca.pem "$@"; }
		for m in googledrive dropbox onedrive pcloud; do
			SETUP=$(wpc eval-file wp-content/oauth-flow.php setup "$m")
			CK=$(echo "$SETUP" | sed -n 's/^COOKIE=//p'); NONCE=$(echo "$SETUP" | sed -n 's/^NONCE=//p')
			# 1. The settings page button: AJAX get_auth_url for instance s-1.
			J=$(browser -b "$CK" --data-urlencode action=sgbackup_ajax --data-urlencode subaction=get_auth_url \
				--data-urlencode "nonce=$NONCE" --data-urlencode "data={\"method\":\"$m\",\"instance\":\"s-1\"}" \
				"http://$INST.test/wp-admin/admin-ajax.php")
			AUTH=$(echo "$J" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["data"]["auth_url"] if d.get("success") else "")' 2>/dev/null || true)
			if [ -z "$AUTH" ]; then echo "  FAIL $m: get_auth_url: $(echo "$J" | head -c 200)"; rc=1; continue; fi
			echo "  ok   $m: sign-in started ($(echo "$AUTH" | cut -d/ -f3))"
			# 2. The provider approves and redirects back.
			CB=$(browser -o /dev/null -w '%{redirect_url}' "$AUTH")
			case "$CB" in "$REDIRECT_URI"\?*) echo "  ok   $m: provider redirected to the registered URI" ;; *) echo "  FAIL $m: provider did not redirect back: $(browser "$AUTH" | head -c 200)"; rc=1; continue ;; esac
			# 3. The REST callback exchanges the code.
			OUT=$(browser -b "$CK" -w '\nHTTP %{http_code}' "$CB")
			if echo "$OUT" | grep -q 'HTTP 200' && echo "$OUT" | grep -q '<h1[^>]*>Connected'; then echo "  ok   $m: callback page says Connected"; else echo "  FAIL $m: callback: $(echo "$OUT" | tr '\n' ' ' | head -c 300)"; rc=1; fi
			# 4. Replaying the same callback must not work (single-use state, single-use code).
			OUT2=$(browser -w '\nHTTP %{http_code}' "$CB")
			if echo "$OUT2" | grep -q 'HTTP 400'; then echo "  ok   $m: replayed callback refused"; else echo "  FAIL $m: replayed callback accepted"; rc=1; fi
			# 5. Token on the right account, usable, and kept across a settings save.
			wpc eval-file wp-content/oauth-flow.php verify "$m" || rc=1
		done
		# A forged state is refused.
		OUT3=$(browser -w '\nHTTP %{http_code}' "$REDIRECT_URI?code=x&state=0123456789abcdef0123456789abcdef")
		echo "$OUT3" | grep -q 'HTTP 400' && echo "  ok   forged state refused" || { echo "  FAIL forged state accepted"; rc=1; }
		# Plain permalinks put a query string in the redirect URI, which Microsoft rejects
		# for personal accounts: the settings page warns about it.
		wpc rewrite structure '' >/dev/null 2>&1 || true
		PLAIN=$(wpc eval-file wp-content/oauth-flow.php redirect 2>/dev/null | tail -1)
		case "$PLAIN" in *\?*) echo "  ok   plain permalinks: redirect URI has a query string ($PLAIN), the settings page shows the warning" ;; *) echo "  FAIL expected a query string with plain permalinks: $PLAIN"; rc=1 ;; esac
		MS=$(browser -w '\nHTTP %{http_code}' "https://login.microsoftonline.com/common/oauth2/v2.0/authorize?client_id=11111111-2222-3333-4444-555555555555&response_type=code&scope=Files.ReadWrite%20offline_access&state=x&redirect_uri=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1],safe=""))' "$PLAIN")")
		echo "$MS" | grep -q AADSTS50011 && echo "  ok   (the Microsoft fake rejects that URI, as Microsoft does)" || { echo "  FAIL microsoft fake accepted a query string"; rc=1; }
		wpc rewrite structure '/%postname%/' >/dev/null 2>&1 || true
		;;
	jobs)
		echo "== Backup job to several destinations at once (settings command -> wp siteguardbackup backup --send-to-cloud)"
		tls_setup; fakes_setup
		KEY=004sg$(openssl rand -hex 6); SECRET=$(openssl rand -hex 16)
		s3tls sgt-s3tls-b2 s3.us-west-004.backblazeb2.com us-west-004 "$KEY" "$SECRET"
		WU=sgdav; WP=$(openssl rand -hex 12)
		docker rm -f sgt-webdav >/dev/null 2>&1 || true
		docker run -d --name sgt-webdav --network wpi-mesh --network-alias webdav.test rclone/rclone:latest serve webdav /data --addr :80 --user "$WU" --pass "$WP" >/dev/null
		sleep 3; docker exec sgt-webdav mkdir -p /data/jobs
		if ! docker ps -q -f name='^sgt-ssh$' | grep -q .; then
			docker rm -f sgt-ssh >/dev/null 2>&1 || true
			SSHPASS=$(openssl rand -hex 12)
			docker run -d --name sgt-ssh --network wpi-mesh --network-alias ssh.test -e PUID=1000 -e PGID=1000 -e USER_NAME=sguser -e PASSWORD_ACCESS=true -e USER_PASSWORD="$SSHPASS" lscr.io/linuxserver/openssh-server:latest >/dev/null
			for _ in $(seq 1 60); do docker exec sgt-ssh sh -c 'nc -z 127.0.0.1 2222' 2>/dev/null && break; sleep 1; done; sleep 3
		fi
		docker exec sgt-ssh sh -c 'mkdir -p /config/jobs && chown 1000:1000 /config/jobs'
		"$IM" compose "$INST" exec -T cli sh -c 'cat > wp-content/job-test.php' < "$HERE/job-test.php"
		wpc eval-file wp-content/job-test.php legacy-email || rc=1
		# Email destination too (through Mailpit), now that it no longer collides with the report address.
		docker rm -f sgt-mail >/dev/null 2>&1 || true
		docker run -d --name sgt-mail --network wpi-mesh --network-alias mail.test -e MP_SMTP_AUTH_ACCEPT_ANY=1 -e MP_SMTP_AUTH_ALLOW_INSECURE=1 axllent/mailpit:latest >/dev/null
		"$IM" compose "$INST" exec -T cli sh -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/sg-test-mail.php' <<'PHP'
<?php
add_action('phpmailer_init', function ($m) { $m->isSMTP(); $m->Host = 'mail.test'; $m->Port = 1025; $m->SMTPAuth = false; $m->SMTPAutoTLS = false; });
PHP
		DEST=$(printf '{"email":{"address":"jobs@example.test"},"b2":{"region":"us-west-004","bucket":"sgjob-b2","path":"jobs","access_key":"%s","secret_key":"%s"},"onedrive":{"client_id":"11111111-2222-3333-4444-555555555555","client_secret":"sg-od-secret","tenant":"common","folder":"SiteGuard Jobs"},"webdav":{"url":"http://webdav.test","user":"%s","pass":"%s","path":"/jobs"},"sftp":{"host":"ssh.test","port":"2222","user":"sguser","pass":"%s","path":"/config/jobs","protocol":"sftp","fingerprint":""}}' "$KEY" "$SECRET" "$WU" "$WP" "$SSHPASS")
		wpc eval-file wp-content/job-test.php configure "$DEST" || { rc=1; continue; }
		wpc eval-file wp-content/job-test.php mkbucket b2 || { rc=1; continue; }
		wpc eval-file wp-content/job-test.php seed-token onedrive '{"access_token":"expired-token","refresh_token":"rt-microsoft","expires_in":3599,"obtained_at":1}'
		# Something worth backing up: a 6 MB upload so OneDrive takes the upload-session path.
		docker exec -u 33 "$(wp_c)" sh -c 'mkdir -p /var/www/html/wp-content/uploads/sgjob && head -c 6291456 /dev/urandom > /var/www/html/wp-content/uploads/sgjob/blob.bin'
		if wpc siteguardbackup backup --send-to-cloud 2>&1 | tail -3; then
			wpc eval-file wp-content/job-test.php verify || rc=1
			sleep 2
			N=$(mesh_curl http://mail.test:8025/api/v1/messages | python3 -c 'import json,sys; print(sum(1 for m in json.load(sys.stdin)["messages"] if m.get("Attachments") and any(t["Address"]=="jobs@example.test" for t in m["To"])))')
			[ "${N:-0}" -ge 1 ] && echo "  ok   email: the job mailed archives to jobs@example.test ($N message(s) with attachments)" || { echo "  FAIL email: no archive mail for the destination address"; rc=1; }
		else
			echo "  FAIL backup job failed"; rc=1
		fi
		docker exec -u 0 "$(wp_c)" rm -rf /var/www/html/wp-content/uploads/sgjob /var/www/html/wp-content/mu-plugins/sg-test-mail.php
		;;
	*) echo "unknown provider $want" >&2; rc=2 ;;
	esac
done
if [ "$FAKES_READY" = 1 ] && [ -n "${SG_FAKES_LOG:-}" ]; then docker logs sgt-fakes > "$SG_FAKES_LOG" 2>&1 || true; fi
exit $rc
