<?php
/**
 * Runs inside WordPress via `wp eval-file --user=admin` (tests/e2e/run.sh):
 * renders Tools → FC Inventory and checks the status rows and that the key
 * form is read-only while FC_INVENTORY_PUBKEYS is set.
 */

require_once ABSPATH . 'wp-admin/includes/template.php';

ob_start();
FcInventory\Admin::render();
$html = (string) ob_get_clean();

$expect = array(
	'sodium row'       => '<code>available</code>',
	'constant source'  => 'constant FC_INVENTORY_PUBKEYS (wp-config.php)',
	'key id listed'    => '<code>e2e</code>',
	'endpoint URL'     => '/wp-json/fc-inventory/v1/inventory',
	'last pull shown'  => '(key e2e)',
	'read-only form'   => 'readonly>',
);
$failures = 0;
foreach ( $expect as $what => $needle ) {
	$ok        = str_contains( $html, $needle );
	$failures += $ok ? 0 : 1;
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . "admin page: $what" );
}
$no_submit = ! str_contains( $html, 'Save keys' );
$failures += $no_submit ? 0 : 1;
WP_CLI::log( ( $no_submit ? 'PASS ' : 'FAIL ' ) . 'admin page: no save button while the constant is set' );
if ( $failures > 0 ) {
	WP_CLI::error( "$failures admin check(s) failed" );
}
WP_CLI::success( 'admin checks passed' );
