#!/usr/bin/env bash
# Functional test in throwaway Docker containers (wordpress:php8.3-apache +
# mariadb:11 + wordpress:cli-php8.3): installs the plugin from a fresh ZIP,
# configures a test key via the FC_INVENTORY_PUBKEYS constant, runs
# tests/e2e/e2e.php as the puller and the WP-CLI commands, then removes
# every container, the network and the volume again.
#
# Usage: tests/e2e/run.sh            (needs docker and php with sodium + zip)
#        FC_E2E_PORT=18081 tests/e2e/run.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${FC_E2E_PORT:-18081}"
BASE="http://localhost:${PORT}"
P="fcinv-e2e-$$"
WORK="$(mktemp -d)"
KEY_ID="e2e"

DB_ENV=(-e WORDPRESS_DB_HOST="${P}-db" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp)

cleanup() {
  docker rm -f "${P}-wp" "${P}-db" >/dev/null 2>&1 || true
  docker network rm "${P}" >/dev/null 2>&1 || true
  docker volume rm "${P}-html" >/dev/null 2>&1 || true
  rm -rf "${WORK}"
}
trap cleanup EXIT

log() { printf '== %s\n' "$*"; }

make_key() {
  php -r '$kp = sodium_crypto_sign_keypair();
    file_put_contents($argv[1], base64_encode(sodium_crypto_sign_secretkey($kp)));
    echo base64_encode(sodium_crypto_sign_publickey($kp));' "${WORK}/secret.key"
}

make_zip() {
  php -r '$z = new ZipArchive(); $z->open($argv[2], ZipArchive::CREATE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1], FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { $z->addFile($f->getPathname(), "fc-inventory/" . substr($f->getPathname(), strlen($argv[1]) + 1)); }
    $z->close();' "${ROOT}/fc-inventory" "${WORK}/fc-inventory.zip"
}

wp_cli() {
  docker run --rm --network "${P}" --user 33:33 -v "${P}-html:/var/www/html" -v "${WORK}:/work:ro" \
    "${DB_ENV[@]}" -e WORDPRESS_CONFIG_EXTRA="${CONFIG_EXTRA}" wordpress:cli-php8.3 wp "$@"
}

wait_for() {
  local what="$1"; shift
  for _ in $(seq 1 60); do
    if "$@" >/dev/null 2>&1; then return 0; fi
    sleep 2
  done
  echo "timeout waiting for ${what}" >&2
  exit 1
}

start_stack() {
  docker network create "${P}" >/dev/null
  docker volume create "${P}-html" >/dev/null
  docker run -d --name "${P}-db" --network "${P}" -e MARIADB_DATABASE=wp -e MARIADB_USER=wp \
    -e MARIADB_PASSWORD=wp -e MARIADB_RANDOM_ROOT_PASSWORD=1 mariadb:11 >/dev/null
  docker run -d --name "${P}-wp" --network "${P}" -p "127.0.0.1:${PORT}:80" -v "${P}-html:/var/www/html" \
    "${DB_ENV[@]}" -e WORDPRESS_CONFIG_EXTRA="${CONFIG_EXTRA}" wordpress:php8.3-apache >/dev/null
  wait_for "WordPress files" docker exec "${P}-wp" test -f /var/www/html/wp-config.php
  wait_for "database" wp_cli db check
}

install_site() {
  wp_cli core install --url="${BASE}" --title=e2e --admin_user=admin \
    --admin_password="$(php -r 'echo bin2hex(random_bytes(12));')" --admin_email=admin@example.com --skip-email
  wp_cli rewrite structure '/%postname%/'
  wp_cli plugin install /work/fc-inventory.zip --activate
}

count_rows() {
  wp_cli db query --skip-column-names \
    "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '%fc\_inventory%'" | tr -d '[:space:]'
}

check_uninstall() {
  wp_cli option update fc_inventory_pubkeys '{"old":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="}' --format=json >/dev/null
  echo "rows before: $(count_rows)"
  wp_cli plugin deactivate fc-inventory
  wp_cli plugin uninstall fc-inventory
  local after
  after="$(count_rows)"
  echo "rows after: ${after}"
  if [ "${after}" != "0" ]; then
    echo "FAIL uninstall left ${after} row(s)" >&2
    exit 1
  fi
  echo "PASS uninstall removed all fc_inventory rows"
}

main() {
  log "key pair and plugin ZIP"
  PUB="$(make_key)"
  CONFIG_EXTRA="define('FC_INVENTORY_PUBKEYS', ['${KEY_ID}' => '${PUB}']);"
  make_zip
  cp "${ROOT}/tests/e2e/updater-check.php" "${ROOT}/tests/e2e/admin-check.php" "${WORK}/"
  log "starting containers (${P})"
  start_stack
  install_site
  log "wp fc-inventory check"
  wp_cli fc-inventory check | head -n 8
  log "wp fc-inventory inventory (plugins)"
  wp_cli fc-inventory inventory | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo json_encode(array_column($d["plugins"], "version", "slug")), "\n";'
  log "HTTP checks"
  php "${ROOT}/tests/e2e/e2e.php" "${BASE}" "${KEY_ID}" "${WORK}/secret.key"
  log "update channel (cached manifest)"
  wp_cli eval-file /work/updater-check.php
  log "Tools → FC Inventory"
  wp_cli eval-file /work/admin-check.php --user=admin
  log "last successful pull (option)"
  wp_cli option get fc_inventory_last_pull --format=json
  log "uninstall removes every stored row"
  check_uninstall
  log "debug log lines of the plugin"
  docker logs "${P}-wp" 2>&1 | grep 'fc-inventory:' | sort | uniq -c || true
}

main
