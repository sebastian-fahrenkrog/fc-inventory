#!/usr/bin/env bash
# Functional test in throwaway Docker containers (wordpress:php8.3-apache +
# mariadb:11 + wordpress:cli-php8.3): installs the plugin from a fresh ZIP,
# configures a test key via the FC_INVENTORY_PUBKEYS constant, runs
# tests/e2e/e2e.php as the puller and the WP-CLI commands, then removes
# every container, the network and the volume again.
#
# Then orders updates through the action endpoint with the Python client
# (tools/fc_action): plugin and theme update, a forced rollback, plugin
# deactivation and a core update.
#
# Usage: tests/e2e/run.sh            (needs docker, php with sodium + zip, and
#                                     python3 with tools/requirements.txt)
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

# Action key: base64 seed for the Python client, prints the public key.
make_action_key() {
  php -r '$seed = random_bytes(32);
    file_put_contents($argv[1], base64_encode($seed) . "\n"); chmod($argv[1], 0600);
    echo base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));' "${WORK}/action.key"
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
  docker run -d --name "${P}-wp" --network "${P}" -p "127.0.0.1:${PORT}:${PORT}" -v "${P}-html:/var/www/html" \
    "${DB_ENV[@]}" -e WORDPRESS_CONFIG_EXTRA="${CONFIG_EXTRA}" wordpress:php8.3-apache >/dev/null
  wait_for "WordPress files" docker exec "${P}-wp" test -f /var/www/html/wp-config.php
  # Apache also listens on the published port, so loopback requests to the
  # site URL (health probes of the action runner) work inside the container.
  docker exec "${P}-wp" sh -c "echo 'Listen ${PORT}' >> /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:80 *:${PORT}>/' /etc/apache2/sites-enabled/000-default.conf \
    && apache2ctl graceful" >/dev/null
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

fc_action() {
  PYTHONPATH="${ROOT}/tools" python3 -m fc_action --config "${WORK}/sites.yml" "$@"
}

expect() {
  local what="$1" want="$2" got="$3"
  if [ "${want}" != "${got}" ]; then
    echo "FAIL ${what}: expected '${want}', got '${got}'" >&2
    exit 1
  fi
  echo "PASS ${what}: ${got}"
}

# Orders through the action endpoint, each checked with WP-CLI afterwards.
check_actions() {
  printf 'key_id: e2e-ops\nkey_file: %s\ntimeout: 300\nsites:\n  e2e: %s\n' "${WORK}/action.key" "${BASE}" > "${WORK}/sites.yml"
  wp_cli plugin install akismet --version=5.3 --force --activate >/dev/null
  wp_cli plugin install hello-dolly --version=1.6 --force --activate >/dev/null
  wp_cli theme install twentytwentyfour --version=1.0 --force >/dev/null

  fc_action update-plugin e2e akismet
  expect "akismet left 5.3" "yes" "$([ "$(wp_cli plugin get akismet --field=version)" != "5.3" ] && echo yes || echo no)"
  expect "akismet still active" "active" "$(wp_cli plugin get akismet --field=status)"
  expect "backup written" "1" "$(docker exec "${P}-wp" sh -c 'ls /var/www/html/wp-content/fc-inventory-backups-*/plugin--akismet--5.3--*.zip | wc -l' | tr -d '[:space:]')"
  expect "backup directory is not served" "403" "$(docker exec "${P}-wp" sh -c 'curl -s -o /dev/null -w "%{http_code}" "http://localhost/wp-content/$(basename /var/www/html/wp-content/fc-inventory-backups-*)/index.php"')"

  fc_action update-plugin e2e akismet
  fc_action update-theme e2e twentytwentyfour
  expect "theme left 1.0" "yes" "$([ "$(wp_cli theme get twentytwentyfour --field=version)" != "1.0" ] && echo yes || echo no)"

  log "forced rollback: the site answers 500 once the upgrade has run"
  cat > "${WORK}/e2e-break.php" <<'PHP'
<?php
// Test helper: once an upgrade has run, every later web request answers 500.
add_action( 'upgrader_process_complete', function () {
	$boom = '<?php if ( ! defined( "WP_CLI" ) ) { http_response_code( 500 ); exit; }';
	file_put_contents( WPMU_PLUGIN_DIR . '/e2e-boom.php', $boom );
} );
PHP
  docker exec "${P}-wp" mkdir -p /var/www/html/wp-content/mu-plugins
  docker cp "${WORK}/e2e-break.php" "${P}-wp:/var/www/html/wp-content/mu-plugins/e2e-break.php" >/dev/null
  docker exec "${P}-wp" chown -R www-data:www-data /var/www/html/wp-content/mu-plugins
  fc_action update-plugin e2e hello-dolly && { echo "FAIL rollback run reported success" >&2; exit 1; }
  docker exec "${P}-wp" rm -f /var/www/html/wp-content/mu-plugins/e2e-break.php /var/www/html/wp-content/mu-plugins/e2e-boom.php
  expect "hello-dolly restored to 1.6" "1.6" "$(wp_cli plugin get hello-dolly --field=version)"
  expect "hello-dolly active after rollback" "active" "$(wp_cli plugin get hello-dolly --field=status)"

  fc_action deactivate-plugin e2e hello-dolly
  expect "hello-dolly deactivated" "inactive" "$(wp_cli plugin get hello-dolly --field=status)"
  fc_action deactivate-plugin e2e fc-inventory && { echo "FAIL fc-inventory deactivated itself" >&2; exit 1; }
  fc_action update-plugin e2e not-installed && { echo "FAIL unknown plugin reported success" >&2; exit 1; }

  log "core: one patch release back, then update on the same branch"
  local current previous
  current="$(wp_cli core version)"
  previous="$(php -r '$p = explode(".", $argv[1]); echo count($p) > 2 && (int) $p[2] > 0 ? "$p[0].$p[1]." . ((int) $p[2] - 1) : "";' "${current}")"
  if [ -n "${previous}" ]; then
    wp_cli core update --version="${previous}" --force >/dev/null
    fc_action update-core e2e
    expect "core back on ${current}" "${current}" "$(wp_cli core version)"
  else
    fc_action update-core e2e
    echo "SKIP core downgrade: ${current} is the first release of its branch"
  fi
  wp_cli option get fc_inventory_last_action --format=json
}

main() {
  log "key pair and plugin ZIP"
  PUB="$(make_key)"
  ACTION_PUB="$(make_action_key)"
  CONFIG_EXTRA="define('FC_INVENTORY_PUBKEYS', ['${KEY_ID}' => '${PUB}']); define('FC_INVENTORY_ACTION_KEYS', ['e2e-ops' => '${ACTION_PUB}']);"
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
  log "actions (signed orders)"
  check_actions
  log "uninstall removes every stored row"
  check_uninstall
  log "debug log lines of the plugin"
  docker logs "${P}-wp" 2>&1 | grep 'fc-inventory:' | sort | uniq -c || true
}

main
