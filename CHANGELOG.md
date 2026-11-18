# Changelog

## 1.1.0

- Signed orders on `fc-inventory/v1/action` (contract action-v1): `update_plugin`, `update_theme`, `update_core` (same branch unless `allow_major`), `deactivate_plugin`. Only versions that WordPress itself offers are installed; the request cannot name a download.
- Separate action keys in the constant `FC_INVENTORY_ACTION_KEYS`; puller keys cannot order changes. Without the constant the route does not exist.
- The request body is part of the signature (SHA-256).
- Before a plugin or theme update the directory is zipped; after it the home and login page are probed, and a failed upgrade or a new 5xx restores the archive (`rolled_back`). Backup directory: `wp-content/fc-inventory-backups-<random>/` or the constant `FC_INVENTORY_BACKUP_DIR`.
- Tools → FC Inventory and `wp fc-inventory check` show the action keys and the last action.
- `tools/fc_action`: Python CLI that signs and sends orders, shared test vector `tests/vectors/action-v1.json`.
- `uninstall.php` also removes the default backup directory.

## 1.0.0

- Signed, read-only inventory endpoint `fc-inventory/v1/inventory` (contract v1, schema 1).
- Ed25519 request signatures with timestamp window and an atomic nonce replay store; every failure, and every other request below `/fc-inventory/v1`, answers like an unknown route, and the namespace is not listed in `/wp-json/`.
- Error log: at most one rejection line per minute.
- `uninstall.php` removes all stored options and transients, on multisite for every site.
- Keys via the constant `FC_INVENTORY_PUBKEYS` or Tools → FC Inventory (network admins only on multisite).
- WP-CLI: `wp fc-inventory check`, `wp fc-inventory inventory`.
- Own update channel: signed manifest from the latest GitHub release, ZIP verified by sha256.
