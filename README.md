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
	'2026-11' => 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=',
) );
```

**Or Tools → FC Inventory** (capability `manage_options`): one key per line,
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
`1794729600`, nonce bytes `0x00..0x0f`, plus the expected message and
signature. Ed25519 is deterministic, so every implementation must reproduce
the signature byte for byte. `scripts/gen-test-vector.php` regenerates it; CI
fails if the committed file differs.

## Security model

- **No secret on the site.** The plugin stores public keys only. A leaked
  `wp-config.php` or database reveals nothing that lets anyone pull.
- **Signature binds host, path and time.** A captured request cannot be
  replayed at another site (host), or later (±300 s window), or twice (each
  nonce is remembered for 600 s after a verified request).
- **Verification** uses `sodium_crypto_sign_verify_detached()`. All inputs are
  checked against strict formats before decoding.
- **Invisible from outside.** Every failure — missing or malformed header,
  unknown key, bad signature, expired timestamp, replayed nonce, missing
  sodium, no key configured — answers the exact 404 `rest_no_route` that
  WordPress returns for a route that does not exist, with the same headers and
  body. The check runs in `rest_pre_dispatch`, before WordPress matches the
  route, so no `Allow` header or other route-specific output gives the plugin
  away. The end-to-end test compares both responses byte by byte. The reason
  goes to the PHP error log only, without request data.
- **Throttle.** After 10 failed checks within 10 minutes, every request gets
  the 404 without verification for 10 minutes. The counter is site-wide, so a
  flood of bad requests also delays the legitimate pull by up to 10 minutes;
  the puller simply retries at its next interval.
- **No caching.** The response sends `nocache_headers()`, `DONOTCACHEPAGE` and
  `Cache-Control: no-store`, so page caches and proxies do not keep it.
- Without `ext-sodium` the route is not registered at all, and the plugin
  logs that.

Security plugins that block the REST API for anonymous visitors also block
this endpoint; allow the route `fc-inventory/v1/inventory` there.

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
vendor/bin/phpunit          # signature checks, test vector, keys, manifest, inventory parsing
tests/e2e/run.sh            # throwaway Docker stack: WordPress + MariaDB + WP-CLI
```

The end-to-end test installs the plugin from a fresh ZIP, configures a test
key through the constant, signs requests as the puller does, checks both URL
forms, compares every rejected request (unsigned, replayed, wrong host,
expired, throttled) byte by byte with WordPress' own 404, runs the WP-CLI
commands and the Tools page, and removes all containers, the network and the
volume at the end.

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
