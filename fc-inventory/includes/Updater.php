<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * Own update channel: a signed manifest from the latest GitHub release.
 * Only a manifest signed with the compiled-in release key is used, and the
 * ZIP is checked against its sha256 before WordPress unpacks it.
 */
final class Updater {
	public const SLUG = 'fc-inventory';

	private const CACHE_KEY   = 'fc_inventory_manifest';
	private const CACHE_TTL   = 12 * HOUR_IN_SECONDS;
	private const FAILURE_TTL = HOUR_IN_SECONDS;

	public static function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'injectUpdate' ) );
		add_filter( 'plugins_api', array( self::class, 'pluginInfo' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'preDownload' ), 10, 4 );
	}

	public static function basename(): string {
		return plugin_basename( FC_INVENTORY_FILE );
	}

	/**
	 * The verified manifest, fetched at most every 12 h (failures: every hour).
	 *
	 * @return array<string,string>|null
	 */
	public static function manifest( bool $refresh = false ): ?array {
		$cached = $refresh ? false : get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && array_key_exists( 'manifest', $cached ) ) {
			return $cached['manifest'];
		}
		$manifest = self::fetch();
		set_site_transient( self::CACHE_KEY, array( 'manifest' => $manifest ), null === $manifest ? self::FAILURE_TTL : self::CACHE_TTL );
		return $manifest;
	}

	private static function fetch(): ?array {
		$json = self::download( Manifest::LATEST_URL . Manifest::MANIFEST );
		$sig  = null === $json ? null : self::download( Manifest::LATEST_URL . Manifest::SIGNATURE );
		if ( null === $sig ) {
			return null;
		}
		$manifest = Manifest::parse( $json, $sig );
		if ( null === $manifest ) {
			error_log( 'fc-inventory: update manifest rejected (signature or fields invalid)' );
		}
		return $manifest;
	}

	private static function download( string $url ): ?string {
		$response = wp_remote_get( $url, array( 'timeout' => 10, 'limit_response_size' => Manifest::MAX_BYTES ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * @param mixed $transient
	 * @return mixed
	 */
	public static function injectUpdate( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$manifest = self::manifest();
		if ( null === $manifest ) {
			return $transient;
		}
		$item = self::updateItem( $manifest );
		$base = self::basename();
		if ( version_compare( $manifest['version'], FC_INVENTORY_VERSION, '>' ) ) {
			$transient->response[ $base ] = $item;
			unset( $transient->no_update[ $base ] );
		} else {
			$transient->no_update[ $base ] = $item;
			unset( $transient->response[ $base ] );
		}
		return $transient;
	}

	private static function updateItem( array $manifest ): object {
		return (object) array(
			'id'           => Manifest::REPO_URL,
			'slug'         => self::SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $manifest['version'],
			'url'          => Manifest::REPO_URL,
			'package'      => $manifest['zip_url'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
			'tested'       => $manifest['tested'],
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	/**
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public static function pluginInfo( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$manifest = self::manifest();
		if ( null === $manifest ) {
			return $result;
		}
		return (object) array(
			'name'          => 'FC Inventory',
			'slug'          => self::SLUG,
			'version'       => $manifest['version'],
			'author'        => 'Sebastian Fahrenkrog',
			'homepage'      => Manifest::REPO_URL,
			'requires'      => $manifest['requires'],
			'requires_php'  => $manifest['requires_php'],
			'tested'        => $manifest['tested'],
			'download_link' => $manifest['zip_url'],
			'sections'      => array(
				'description' => esc_html( 'Signed, read-only inventory endpoint for a Dependency-Track puller.' ),
				'changelog'   => nl2br( esc_html( $manifest['changelog'] ) ),
			),
		);
	}

	/**
	 * Downloads this plugin's package itself and verifies its sha256.
	 * Anything that is not this plugin's update passes through unchanged.
	 *
	 * @param mixed  $reply
	 * @param string $package
	 * @param mixed  $upgrader
	 * @param array  $hook_extra
	 * @return mixed
	 */
	public static function preDownload( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( ! self::isOurPackage( (string) $package, is_array( $hook_extra ) ? $hook_extra : array() ) ) {
			return $reply;
		}
		$manifest = self::manifest();
		if ( null === $manifest || $package !== $manifest['zip_url'] ) {
			return new \WP_Error( 'fc_inventory_unverified', 'FC Inventory: the update package does not match the signed manifest.' );
		}
		return self::downloadVerified( $package, $manifest['sha256'] );
	}

	private static function isOurPackage( string $package, array $hook_extra ): bool {
		return self::basename() === ( $hook_extra['plugin'] ?? '' ) || str_starts_with( $package, Manifest::ZIP_PREFIX );
	}

	/** @return string|\WP_Error path of the verified temporary file */
	private static function downloadVerified( string $url, string $sha256 ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $url, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! hash_equals( $sha256, (string) hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			error_log( 'fc-inventory: update aborted, sha256 of the package does not match the manifest' );
			return new \WP_Error( 'fc_inventory_checksum', 'FC Inventory: the update package failed the sha256 check; update aborted.' );
		}
		return $tmp;
	}
}
