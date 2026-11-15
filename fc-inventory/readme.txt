=== FC Inventory ===
Contributors: sebastianfahrenkrog
Tags: security, inventory, sbom, dependency-track
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Signed, read-only inventory endpoint (core, plugins, themes, Composer packages) for a Dependency-Track puller.

== Description ==

The plugin answers GET /wp-json/fc-inventory/v1/inventory with a JSON
inventory of the site, but only to requests signed with an Ed25519 key
whose public half is configured on the site. Every other request gets the
same 404 that WordPress returns for a route that does not exist.

See https://github.com/sebastian-fahrenkrog/fc-inventory for configuration,
the security model and the update channel.

== Changelog ==

= 1.0.0 =
* First release: signed inventory endpoint, Tools page, WP-CLI commands, signed update channel.
