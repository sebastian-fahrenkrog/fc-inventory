<?php
/**
 * Runs inside WordPress via `wp eval-file` (tests/e2e/run.sh). Seeds the
 * manifest cache with a newer version and checks that the update channel
 * offers it, answers plugins_api and refuses a package the manifest does
 * not name. The network fetch itself is not exercised here.
 */

use FcInventory\Manifest;
use FcInventory\Updater;

$manifest = array(
	'version'      => '99.0.0',
	'zip_url'      => Manifest::ZIP_PREFIX . 'v99.0.0/fc-inventory.zip',
	'sha256'       => str_repeat( '0', 64 ),
	'requires'     => '6.4',
	'requires_php' => '8.1',
	'tested'       => '7.1',
	'changelog'    => 'Test <b>entry</b>.',
);
set_site_transient( 'fc_inventory_manifest', array( 'manifest' => $manifest ), 60 );

$failures = 0;
$check    = function ( bool $ok, string $what ) use ( &$failures ): void {
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $what );
	$failures += $ok ? 0 : 1;
};

$transient = Updater::injectUpdate( (object) array( 'response' => array(), 'no_update' => array() ) );
$item      = $transient->response[ Updater::basename() ] ?? null;
$check( null !== $item && '99.0.0' === $item->new_version, 'update offered from cached manifest' );
$check( null !== $item && $manifest['zip_url'] === $item->package, 'package URL taken from manifest' );

$info = Updater::pluginInfo( false, 'plugin_information', (object) array( 'slug' => 'fc-inventory' ) );
$check( is_object( $info ) && '99.0.0' === $info->version, 'plugins_api answers for fc-inventory' );
$check( is_object( $info ) && ! str_contains( $info->sections['changelog'], '<b>' ), 'changelog is escaped' );
$check( false === Updater::pluginInfo( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ), 'plugins_api ignores other slugs' );

$reply = Updater::preDownload( false, Manifest::ZIP_PREFIX . 'v98.0.0/fc-inventory.zip', null, array( 'plugin' => Updater::basename() ) );
$check( is_wp_error( $reply ) && 'fc_inventory_unverified' === $reply->get_error_code(), 'package not named by the manifest is refused' );
$check( false === Updater::preDownload( false, 'https://downloads.wordpress.org/plugin/akismet.zip', null, array( 'plugin' => 'akismet/akismet.php' ) ), 'other packages pass through' );

delete_site_transient( 'fc_inventory_manifest' );

if ( $failures > 0 ) {
	WP_CLI::error( "$failures updater check(s) failed" );
}
WP_CLI::success( 'updater checks passed' );
