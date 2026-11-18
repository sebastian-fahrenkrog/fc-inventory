<?php
/**
 * Loads all plugin classes. Pure classes (no WordPress calls at load time)
 * come first so the unit tests can require them without WordPress.
 */

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

require_once __DIR__ . '/AuthFailure.php';
require_once __DIR__ . '/NonceStore.php';
require_once __DIR__ . '/Signature.php';
require_once __DIR__ . '/Keys.php';
require_once __DIR__ . '/Verifier.php';
require_once __DIR__ . '/Manifest.php';
require_once __DIR__ . '/FileScanner.php';
require_once __DIR__ . '/Inventory.php';
require_once __DIR__ . '/InvalidAction.php';
require_once __DIR__ . '/ActionRequest.php';
require_once __DIR__ . '/CoreOffer.php';

if ( defined( 'ABSPATH' ) ) {
	require_once __DIR__ . '/OptionNonceStore.php';
	require_once __DIR__ . '/Health.php';
	require_once __DIR__ . '/Backup.php';
	require_once __DIR__ . '/ActionRunner.php';
	require_once __DIR__ . '/Endpoint.php';
	require_once __DIR__ . '/Admin.php';
	require_once __DIR__ . '/Updater.php';
	require_once __DIR__ . '/Plugin.php';
}
