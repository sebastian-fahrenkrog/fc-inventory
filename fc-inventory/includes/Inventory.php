<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * Builds the response document (contract v1, schema 1). build() is pure;
 * fromWordPress() gathers the context from the running site.
 */
final class Inventory {
	public const SCHEMA = 1;

	/**
	 * @param array{
	 *   plugin_version: string, generated_at: string, site_url: string, home_url: string,
	 *   multisite: bool, active_plugins: list<string>, network_plugins: list<string>,
	 *   stylesheet: string, template: string, env: array<string,string>
	 * } $ctx
	 * @return array<string,mixed>
	 */
	public static function build( FileScanner $scanner, array $ctx ): array {
		$plugins = $scanner->plugins();
		$themes  = $scanner->themes();
		return array(
			'schema'         => self::SCHEMA,
			'plugin_version' => $ctx['plugin_version'],
			'generated_at'   => $ctx['generated_at'],
			'site_url'       => $ctx['site_url'],
			'home_url'       => $ctx['home_url'],
			'multisite'      => $ctx['multisite'],
			'core'           => $scanner->coreVersion(),
			'plugins'        => self::pluginRows( $plugins, $ctx['active_plugins'], $ctx['network_plugins'] ),
			'mu_plugins'     => $scanner->muPlugins(),
			'dropins'        => $scanner->dropins(),
			'themes'         => self::themeRows( $themes, $ctx['stylesheet'], $ctx['template'] ),
			'composer'       => self::composerRows( $plugins, $themes ),
			'env'            => $ctx['env'],
		);
	}

	private static function pluginRows( array $plugins, array $active, array $network ): array {
		$out = array();
		foreach ( $plugins as $p ) {
			$network_active = in_array( $p['file'], $network, true );
			$out[]          = array(
				'slug'           => $p['slug'],
				'file'           => $p['file'],
				'name'           => $p['name'],
				'version'        => $p['version'],
				'active'         => $network_active || in_array( $p['file'], $active, true ),
				'network_active' => $network_active,
				'single_file'    => $p['single_file'],
			);
		}
		return $out;
	}

	/** The parent of the active child theme counts as active: its code runs. */
	private static function themeRows( array $themes, string $stylesheet, string $template ): array {
		$out = array();
		foreach ( $themes as $t ) {
			$out[] = array(
				'slug'    => $t['slug'],
				'name'    => $t['name'],
				'version' => $t['version'],
				'active'  => $t['slug'] === $stylesheet || $t['slug'] === $template,
				'parent'  => $t['parent'],
			);
		}
		return $out;
	}

	private static function composerRows( array $plugins, array $themes ): array {
		$out = array();
		foreach ( array( 'plugin' => $plugins, 'theme' => $themes ) as $kind => $items ) {
			foreach ( $items as $item ) {
				$packages = FileScanner::composerPackages( $item['dir'] );
				if ( null !== $packages ) {
					$out[] = array( 'owner' => $kind . ':' . $item['slug'], 'packages' => $packages );
				}
			}
		}
		return $out;
	}

	/**
	 * The inventory of the running site.
	 *
	 * @return array<string,mixed>
	 */
	public static function fromWordPress(): array {
		return self::build( self::scanner(), self::context() );
	}

	public static function scanner(): FileScanner {
		return new FileScanner(
			untrailingslashit( ABSPATH ),
			untrailingslashit( WP_CONTENT_DIR ),
			untrailingslashit( WP_PLUGIN_DIR ),
			untrailingslashit( WPMU_PLUGIN_DIR ),
			untrailingslashit( get_theme_root() )
		);
	}

	private static function context(): array {
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		return array(
			'plugin_version'  => FC_INVENTORY_VERSION,
			'generated_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site_url'        => site_url(),
			'home_url'        => home_url(),
			'multisite'       => is_multisite(),
			'active_plugins'  => array_values( (array) get_option( 'active_plugins', array() ) ),
			'network_plugins' => $network,
			'stylesheet'      => (string) get_stylesheet(),
			'template'        => (string) get_template(),
			'env'             => self::env(),
		);
	}

	/** @return array<string,string> */
	private static function env(): array {
		global $wpdb;
		return array(
			'php'    => PHP_VERSION,
			'sapi'   => PHP_SAPI,
			'db'     => is_object( $wpdb ) ? (string) $wpdb->db_server_info() : '',
			'server' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
		);
	}
}
