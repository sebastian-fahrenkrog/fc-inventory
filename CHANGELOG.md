# Changelog

## 1.0.0

- Signed, read-only inventory endpoint `fc-inventory/v1/inventory` (contract v1, schema 1).
- Ed25519 request signatures with timestamp window, nonce replay store and failure throttle; every failure answers the same 404 as an unknown route.
- Keys via the constant `FC_INVENTORY_PUBKEYS` or Tools → FC Inventory.
- WP-CLI: `wp fc-inventory check`, `wp fc-inventory inventory`.
- Own update channel: signed manifest from the latest GitHub release, ZIP verified by sha256.
