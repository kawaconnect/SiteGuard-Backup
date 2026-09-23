#!/usr/bin/env bash
# =============================================================================
# Remote storage tests against local emulators on the isolated instance mesh.
#
#   tests/integration/storage.sh <instance-manager> <instance> [s3] [azure] [webdav]
#
# Starts MinIO (S3 API) and Azurite (Azure Blob API, TLS with a throwaway CA)
# as containers on the internal "wpi-mesh" network, then runs
# storage-test.php inside <instance> for each: credentials test, small and
# multipart/block uploads, listing, byte-identical downloads, unsafe-name
# delete guard, delete, and a failed download leaving nothing behind.
# The emulators and the test CA are removed afterwards.
# =============================================================================
set -euo pipefail

IM="${1:?instance manager required}"
INST="${2:?instance name required}"
shift 2
WANT=("$@"); [ ${#WANT[@]} -gt 0 ] || WANT=(s3 azure webdav)
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ "$INST" =~ ^[a-z0-9][a-z0-9-]{0,30}$ ]] || { echo "bad instance name" >&2; exit 2; }

WORK="$(mktemp -d /tmp/sg-storage.XXXXXX)"
cleanup() {
	docker rm -f sgt-minio sgt-azurite sgt-webdav >/dev/null 2>&1 || true
	"$IM" compose "$INST" exec -T cli sh -c 'rm -f wp-content/mu-plugins/sg-test-ca.php wp-content/sg-test-ca.pem wp-content/storage-test.php' >/dev/null 2>&1 || true
	rm -rf --one-file-system -- "$WORK"
}
trap cleanup EXIT

wpc() { "$IM" wp "$INST" "$@" 2> >(grep -v -e wp_update -e 'Warning: .*WordPress.org' >&2); }
wp_c() { "$IM" compose "$INST" ps -q wordpress; }

# The test script runs from inside the instance.
docker cp -q "$HERE/storage-test.php" "$(wp_c):/var/www/html/wp-content/storage-test.php"
docker exec -u 0 "$(wp_c)" chown 33:33 /var/www/html/wp-content/storage-test.php

rc=0
for want in "${WANT[@]}"; do
	case "$want" in
	s3)
		echo "== S3 (MinIO, path-style over http with allow_insecure)"
		KEY=sgtest$(openssl rand -hex 4); SECRET=$(openssl rand -hex 16)
		docker rm -f sgt-minio >/dev/null 2>&1 || true
		docker run -d --name sgt-minio --network wpi-mesh --network-alias minio.test \
			-e MINIO_ROOT_USER="$KEY" -e MINIO_ROOT_PASSWORD="$SECRET" \
			quay.io/minio/minio server /data >/dev/null
		for _ in $(seq 1 60); do docker logs sgt-minio 2>&1 | grep -qi "API:" && break; sleep 1; done
		OPTS=$(printf '{"__create":true,"provider":"minio","endpoint":"http://minio.test:9000","allow_insecure":true,"region":"us-east-1","bucket":"sgtest-bucket","path":"sites/a","access_key":"%s","secret_key":"%s"}' "$KEY" "$SECRET")
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
	*) echo "unknown provider $want" >&2; rc=2 ;;
	esac
done
exit $rc
