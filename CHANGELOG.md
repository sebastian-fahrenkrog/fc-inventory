# Changelog

## 1.0.0

- Signed, read-only inventory endpoint `fc-inventory/v1/inventory` (contract v1, schema 1).
- Ed25519 request signatures with timestamp window and an atomic nonce replay store; every failure, and every other request below `/fc-inventory/v1`, answers like an unknown route, and the namespace is not listed in `/wp-json/`.
- Error log: at most one rejection line per minute.
- `uninstall.php` removes all stored options and transients, on multisite for every site.
- Keys via the constant `FC_INVENTORY_PUBKEYS` or Tools → FC Inventory (network admins only on multisite).
- WP-CLI: `wp fc-inventory check`, `wp fc-inventory inventory`.
- Own update channel: signed manifest from the latest GitHub release, ZIP verified by sha256.
