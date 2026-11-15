<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the parts into WordPress.
 */
final class Plugin {
	public static function boot(): void {
		Endpoint::register();
		Updater::register();
		if ( is_admin() ) {
			Admin::register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/Cli.php';
			\WP_CLI::add_command( 'fc-inventory', Cli::class );
		}
	}
}
