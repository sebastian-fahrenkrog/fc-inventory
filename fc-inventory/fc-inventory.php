<?php
/**
 * Plugin Name:       FC Inventory
 * Plugin URI:        https://github.com/sebastian-fahrenkrog/fc-inventory
 * Description:       Inventory endpoint (core, plugins, themes, Composer packages) for a Dependency-Track puller, plus signed update orders (plugin, theme, core) with backup and rollback. Requests must carry an Ed25519 signature; anything else gets a plain 404.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Sebastian Fahrenkrog
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/sebastian-fahrenkrog/fc-inventory
 * Text Domain:       fc-inventory
 */

defined( 'ABSPATH' ) || exit;

define( 'FC_INVENTORY_VERSION', '1.1.0' );
define( 'FC_INVENTORY_FILE', __FILE__ );

require_once __DIR__ . '/includes/load.php';

FcInventory\Plugin::boot();
