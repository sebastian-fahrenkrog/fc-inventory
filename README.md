# FC Inventory

A small WordPress plugin that offers a **signed, read-only inventory** of a
site — WordPress core, plugins, mu-plugins, drop-ins, themes and the Composer
packages bundled in plugins and themes — to a puller that turns it into a
CycloneDX SBOM for [Dependency-Track](https://dependencytrack.org/).

It exists for managed hosting (shared webspace, chroot, no root, no Docker),
where a scanner binary cannot run. The site holds **no secret**: it knows only
the puller's Ed25519 public key. The puller holds the private key and the
Dependency-Track upload keys.

```
webspace                                   puller host
┌──────────────────────────────┐  GET       ┌─────────────────────────────────┐
│ fc-inventory                 │ ◀───────── │ wp-puller                       │
│ /wp-json/fc-inventory/v1/... │  Ed25519   │ private key, sites.yml          │
│ verifies the signature       │  signed    │ inventory → CycloneDX → upload  │
│ returns the raw inventory    │ ─────────▶ │ to Dependency-Track             │
└──────────────────────────────┘  JSON      └─────────────────────────────────┘
```

Since 1.1 the plugin can also **carry out signed orders**: update a plugin, a
theme or WordPress core, or deactivate a plugin (see [Actions](#actions-contract-action-v1)).
Orders need a separate key and are off until that key is configured.

Requirements: PHP ≥ 8.1 with `ext-sodium` (standard since PHP 7.2),
WordPress ≥ 6.4. No third-party runtime libraries.

## Install

Download `fc-inventory.zip` from the
[latest release](https://github.com/sebastian-fahrenkrog/fc-inventory/releases/latest)
and install it like any plugin:

```sh
wp plugin install https://github.com/sebastian-fahrenkrog/fc-inventory/releases/latest/download/fc-inventory.zip --activate
```

or upload it under Plugins → Add New → Upload. The ZIP unpacks to
`wp-content/plugins/fc-inventory/`.

## Configure

The puller generates its key pair (`wp-puller keygen`) and prints the public
key. Configure that public key on the site, either way:

**Constant in `wp-config.php`** (wins when set):

```php
define( 'FC_INVENTORY_PUBKEYS', array(
	'2026-11' => '<base64 public key from wp-puller keygen>',
) );
```

**Or Tools → FC Inventory** (capability `manage_options`, on multisite
`manage_network_options`): one key per line,
`<key-id> <base64 public key>`. While the constant is set, the form is
read-only and says so.

Key ids are 1–32 characters of `A-Z a-z 0-9 . _ -`; keys are 32 bytes in
standard base64. Several keys can be configured at once, which allows rotation
without downtime: add the new key, switch the puller, remove the old key.

Check the setup with WP-CLI:

```sh
wp fc-inventory check       # sodium, keys, endpoint URLs, local dry run of the response
wp fc-inventory inventory   # the inventory JSON only
```

The Tools page shows the same status plus the last successful pull (time and
key id).

## Endpoint and contract (v1)

```
GET /wp-json/fc-inventory/v1/inventory
GET /?rest_route=/fc-inventory/v1/inventory      (fallback without pretty permalinks)

X-FC-Key-Id:     2026-11
X-FC-Timestamp:  1794729600                     Unix seconds
X-FC-Nonce:      AAECAwQFBgcICQoLDA0ODw         16 random bytes, base64url, no padding
X-FC-Signature:  <Ed25519 detached signature, standard base64>
```

The signed message joins these fields with `\n`, without a trailing newline:

```
fc-inventory-v1
GET
<host>        lowercase, default port stripped, punycode for IDN
/wp-json/fc-inventory/v1/inventory      (the same for both URL forms)
<timestamp>
<nonce>
```

The plugin takes `<host>` from `HTTP_HOST`. Custom `X-` headers are used
because some PHP-FPM setups drop `Authorization`.

The response is `200 application/json` with `Cache-Control: no-store`:
`schema` (1), `plugin_version`, `generated_at`, `site_url`, `home_url`,
`multisite`, `core`, `plugins[]` (slug, file, name, version, active,
network_active, single_file), `mu_plugins[]`, `dropins[]`, `themes[]` (slug,
name, version, active, parent), `composer[]` (owner `plugin:<slug>` or
`theme:<slug>`, packages from `vendor/composer/installed.json`) and `env`
(PHP version, SAPI, database server version, web server software).
Users, options and file paths are not part of it.

Versions are read from the files, the way WordPress' `get_file_data()` does
(first 8 KiB, first match per header), without database writes. Slugs are the
directory name for directory plugins and themes and the file name without
`.php` for single-file plugins (`hello.php` → `hello-dolly`). `composer` lists
only plugins and themes that ship `vendor/composer/installed.json`.

### Test vector

`tests/vectors/signature-v1.json` is the shared vector of contract v1: key
pair from seed = 32 bytes of `0x01` (`sodium_crypto_sign_seed_keypair` in PHP,
`ed25519.NewKeyFromSeed` in Go), host `www.example.com`, timestamp
`1794729600`, and one case per nonce (bytes `0x00..0x0f`, and 16 × `0x01`, the
case the Go puller computed independently), each with the expected message
and signature. The top-level `nonce`/`message`/`signature` repeat the first
case. Ed25519 is deterministic, so every implementation must reproduce each
signature byte for byte. `scripts/gen-test-vector.php` regenerates it; CI
fails if the committed file differs.

## Security model

- **No secret on the site.** The plugin stores public keys only. A leaked
  `wp-config.php` or database reveals nothing that lets anyone pull.
- **Signature binds host, path and time.** A captured request cannot be
  replayed at another site (host), or later (±300 s window), or twice (each
  nonce is remembered for 600 s after a verified request).
- **Verification** uses `sodium_crypto_sign_verify_detached()`. All inputs are
  checked against strict formats before decoding.
- **Replay store.** Each verified nonce is stored as one row in `wp_options`
  with a single `INSERT IGNORE` on the unique option name, so of two parallel
  requests with the same nonce only one gets through. A failed insert counts
  as a replay. Expired rows are deleted after each successful pull.
- **Failures look like an unknown route.** Every failure — missing or
  malformed header, unknown key, bad signature, expired timestamp, replayed
  nonce, no key configured — and every other request below
  `/fc-inventory/v1` (the namespace index, other methods, unknown sub-routes)
  gets the same response WordPress gives for a route that does not exist: the
  404 `rest_no_route`, or for `OPTIONS` the empty 200. The namespace is left
  out of the `/wp-json/` index. The end-to-end test compares these responses
  byte by byte. This keeps the endpoint quiet; it is not a secret. The plugin
  files can be fetched like those of any plugin, so the plugin's presence can
  be detected. Security rests on the signature alone.
- **No failure throttle.** Verifying an Ed25519 signature costs less than the
  WordPress bootstrap every request pays anyway, and a site-wide lockout would
  let anyone block the real puller with a few unsigned requests. Instead the
  error log gets at most one rejection line per minute, with a fixed reason
  and no request data.
- **No caching.** The response sends `nocache_headers()`, `DONOTCACHEPAGE` and
  `Cache-Control: no-store`, so page caches and proxies do not keep it.
- Without `ext-sodium` the route is not registered at all, and the plugin
  logs that.

Security plugins that block the REST API for anonymous visitors also block
this endpoint; allow the route `fc-inventory/v1/inventory` there.

## Actions (contract action-v1)

Closing a vulnerability usually means updating one plugin. With an action
key configured, the site accepts signed orders for exactly four things:

| Action | Effect |
| --- | --- |
| `update_plugin` | updates one plugin to the version WordPress offers |
| `update_theme` | updates one theme to the version WordPress offers |
| `update_core` | updates core within its `x.y` branch; `"allow_major": true` also moves to a newer branch |
| `deactivate_plugin` | deactivates one plugin (when no fixed version exists) |

It works on any host where WordPress can write its own files directly
(`FS_METHOD` `direct`, the usual case): shared hosting, a VPS, a container.
Nothing in it is specific to one provider.

### Configure

Orders verify against their own key list, a constant in `wp-config.php`:

```php
define( 'FC_INVENTORY_ACTION_KEYS', array( 'ops-2026-11' => '<base64 Ed25519 public key>' ) );
```

- There is **no** option and no form for action keys: write access cannot be
  granted from wp-admin.
- A puller key from `FC_INVENTORY_PUBKEYS` cannot order anything, and an
  action key cannot read the inventory, unless the same key is entered in both.
- Without the constant the action route does not exist (plain 404).
- Optional: `define( 'FC_INVENTORY_BACKUP_DIR', '/path/outside/the/webroot' );`

### Request

```
POST /wp-json/fc-inventory/v1/action      (or /?rest_route=/fc-inventory/v1/action)
X-FC-Key-Id, X-FC-Timestamp, X-FC-Nonce, X-FC-Signature     as for the inventory
Content-Type: application/json

{"schema":1,"action":"update_plugin","slug":"akismet"}
```

Signed message, fields joined by `\n`, no trailing newline:

```
fc-inventory-action-v1
POST
<host>
/wp-json/fc-inventory/v1/action
<timestamp>
<nonce>
<sha256 of the raw request body, lowercase hex>
```

The body hash makes the order part of the signature. `slug` is the directory
name as in the inventory (the file name without `.php` for single-file
plugins). Unknown fields are rejected. The request **never names a download**:
the plugin installs only what the site's own update check offers, which
includes the update channels of commercial plugins.

### What happens

1. One order at a time (a lock row; a second order gets `failed`).
2. The update data is refreshed, the offered version looked up.
3. Loopback probes of the home page and the login page record the status.
4. The plugin or theme directory is zipped into the backup directory
   (default `wp-content/fc-inventory-backups-<random>/`, protected by
   `.htaccess` and an unguessable name; the newest three archives per plugin
   or theme are kept).
5. WordPress' own upgrader installs the update. A plugin that was active is
   active again afterwards.
6. The probes run again. If the upgrade failed, or a URL that answered before
   now answers 5xx or not at all, the archive is restored and the result is
   `rolled_back`.

Core updates have no file backup; WordPress rolls a failed core update back
itself, and the database upgrade runs in a fresh request as after an automatic
update. Where a host blocks loopback requests the probes report `0`; the
update still runs, without the automatic check, and the answer says so.

### Response

`200` with `Cache-Control: no-store`:

```json
{
  "schema": 1, "action": "update_plugin", "slug": "akismet",
  "result": "updated", "from": "5.3", "to": "5.7.2", "offered": "5.7.2",
  "backup": "plugin--akismet--5.3--20261118-113728.zip",
  "health": {"before": {"home": 200, "login": 200}, "after": {"home": 200, "login": 200}},
  "messages": ["…"]
}
```

`result` is one of `updated`, `no_update`, `major_only` (core: only a newer
branch is offered), `deactivated`, `not_active`, `rolled_back`, `failed`. A
correctly signed request with an invalid body gets `400` and `result:
invalid`. Everything unsigned gets the same 404 as an unknown route. Each
order is written to the error log and shown as "Last action" on Tools → FC
Inventory.

### Client: `tools/fc_action`

A small Python CLI signs and sends orders (`pip install -r tools/requirements.txt`):

```sh
cd tools
python3 -m fc_action keygen --out ~/.config/fc-action/ops-2026-11.key --key-id ops-2026-11
cp sites.example.yml ~/.config/fc-action/sites.yml      # key id, key file, site URLs
python3 -m fc_action update-plugin example akismet elementor
python3 -m fc_action update-theme example hello-elementor
python3 -m fc_action update-core example [--allow-major]
python3 -m fc_action deactivate-plugin example some-plugin
```

`keygen` prints the `wp-config.php` line with the public key; the private key
(a base64 seed, mode 600) never leaves the machine. The exit status is 1 if
an order failed or was rolled back. `tests/vectors/action-v1.json` is the
shared test vector of the PHP and the Python side.

### Security notes

- An action key can change code on the site. Keep it apart from the puller
  key and off the puller host if the puller does not need it.
- Host, path, time, nonce and body are signed; an order cannot be replayed,
  moved to another site or altered.
- The worst an attacker with a stolen action key can do is update or
  deactivate what is installed. They cannot install anything new or choose a
  download.

## Update channel

The plugin updates itself from this repository's releases, without a
third-party updater library:

- Every 12 hours at most, it fetches `fc-inventory.json` and
  `fc-inventory.json.sig` from
  `https://github.com/sebastian-fahrenkrog/fc-inventory/releases/latest/download/`.
- The manifest (`version`, `zip_url`, `sha256`, `requires`, `requires_php`,
  `tested`, `changelog`) is used only if its Ed25519 signature verifies
  against the **release public key compiled into the plugin**
  (`FcInventory\Manifest::RELEASE_PUBKEY`; separate from the puller keys), and
  `zip_url` must point to this repository's release downloads.
- In `upgrader_pre_download` the plugin downloads the ZIP itself and checks
  its SHA-256 against the manifest before WordPress unpacks it. A mismatch
  aborts the update.
- With a valid newer version WordPress shows the normal "update available"
  notice, the details popup comes from `plugins_api`, and auto-updates work
  like for any other plugin. The `Update URI` header keeps wordpress.org from
  offering a same-named plugin.

## Development

```sh
composer install
vendor/bin/phpunit          # signature checks, test vectors, keys, manifest, inventory parsing, orders
(cd tools && python3 -m unittest discover -s tests -t .)   # Python client against the action vector
tests/e2e/run.sh            # throwaway Docker stack: WordPress + MariaDB + WP-CLI
```

The end-to-end test also sends real orders with the Python client: a plugin
and a theme update from an old version, a forced rollback (the site answers
500 after the upgrade), deactivation, the refusal to deactivate itself, and a
core update one patch release up.

The end-to-end test installs the plugin from a fresh ZIP, configures a test
key through the constant, signs requests as the puller does, checks both URL
forms, compares every rejected request (unsigned, replayed, wrong host,
expired, namespace index, `OPTIONS`, `POST`) byte by byte with the same
request to an unknown route, checks that `/wp-json/` does not list the
namespace and that 15 unsigned requests do not lock out a signed one, runs the
WP-CLI commands, the Tools page, the update channel from a cached manifest and
the uninstall, and removes all containers, the network and the volume at the
end.

## Uninstall

Deleting the plugin in wp-admin runs `uninstall.php`, which removes the key
option, the last-pull record, the nonce rows, the log-gate transient and the
cached update manifest — on multisite for every site. A key set through the
constant stays in `wp-config.php`.

## Release process

1. Bump the version in `fc-inventory/fc-inventory.php` (header and
   `FC_INVENTORY_VERSION`) and `Stable tag` in `fc-inventory/readme.txt`, add a
   `## <version>` section to `CHANGELOG.md`.
2. Commit, tag `v<version>`, push the tag.
3. `.github/workflows/release.yml` runs the tests, then
   `scripts/build-release.php`, which builds `fc-inventory.zip`, the manifest
   and its signature with the secret `FC_INVENTORY_SIGNING_KEY` (base64
   Ed25519 secret key), refuses to sign if the key does not match the
   compiled-in release public key or the versions disagree, and creates the
   GitHub release with the three assets.

A local build works the same way:

```sh
php scripts/build-release.php --version=1.0.0 --out=dist --key-file="$HOME/.config/fc-inventory/release-signing.key"
```

The release signing key never lives in this repository. Losing it means
shipping one release with a new public key by hand (installing the ZIP
manually), since the plugin accepts only manifests signed with the compiled-in
key.

## License

GPL-2.0-or-later.
