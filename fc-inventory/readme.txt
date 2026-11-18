=== FC Inventory ===
Contributors: sebastianfahrenkrog
Tags: security, inventory, sbom, dependency-track
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Signed inventory endpoint (core, plugins, themes, Composer packages) for a Dependency-Track puller, plus signed update orders.

== Description ==

The plugin answers GET /wp-json/fc-inventory/v1/inventory with a JSON
inventory of the site, but only to requests signed with an Ed25519 key
whose public half is configured on the site. Every other request gets the
same 404 that WordPress returns for a route that does not exist.

See https://github.com/sebastian-fahrenkrog/fc-inventory for configuration,
the security model and the update channel.

Since 1.1 the plugin also accepts signed orders on
POST /wp-json/fc-inventory/v1/action: update a plugin, a theme or core, or
deactivate a plugin. Orders need a separate key in the constant
FC_INVENTORY_ACTION_KEYS and are off without it. Plugins and themes are
backed up first and restored if the site answers with a server error
afterwards.

== Changelog ==

= 1.1.0 =
* Signed update orders (plugin, theme, core, deactivate plugin) with backup and automatic rollback; separate action keys.

= 1.0.0 =
* First release: signed inventory endpoint, Tools page, WP-CLI commands, signed update channel.
