<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * REST route fc-inventory/v1/inventory.
 *
 * Every request below /fc-inventory/v1 is handled in rest_pre_dispatch,
 * before WordPress matches a route: only a verified GET/HEAD on the inventory
 * route passes. Everything else (other methods, the namespace index,
 * unknown sub-routes, failed signatures) gets the same WP_Error that
 * WordPress builds for an unknown route, and OPTIONS gets the empty 200 that
 * WordPress sends for OPTIONS on an unknown route. Because no route is
 * matched at that point, no Allow header or route description is added and
 * the 404 equals the one for a route that does not exist. The route and its
 * namespace are also left out of the /wp-json/ index. The permission_callback
 * only confirms that this request passed the check.
 */
final class Endpoint {
	public const NAMESPACE   = 'fc-inventory/v1';
	public const ROUTE       = '/inventory';
	public const LAST_PULL   = 'fc_inventory_last_pull';
	public const LOG_GATE    = 'fc_inventory_log_gate';
	private const PREFIX     = '/' . self::NAMESPACE;
	private const FULL_ROUTE = self::PREFIX . self::ROUTE;

	/** At most one rejection line per this many seconds in the error log. */
	private const LOG_INTERVAL = 60;

	private static bool $verified = false;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoute' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'preDispatch' ), -100, 3 );
		add_filter( 'rest_index', array( self::class, 'hideFromIndex' ) );
	}

	public static function sodiumAvailable(): bool {
		return function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	/** Without sodium the route does not exist at all, so WordPress answers 404. */
	public static function registerRoute(): void {
		if ( ! self::sodiumAvailable() ) {
			error_log( 'fc-inventory: ext-sodium is missing, endpoint disabled' );
			return;
		}
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'respond' ),
				'permission_callback' => array( self::class, 'permission' ),
				'show_in_index'       => false,
			)
		);
	}

	/**
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function preDispatch( $result, $server, $request ) {
		if ( null !== $result || ! self::sodiumAvailable() ) {
			return $result;
		}
		$route = strtolower( $request->get_route() );
		if ( ! str_starts_with( $route, self::PREFIX ) ) {
			return $result;
		}
		if ( 'OPTIONS' === $request->get_method() ) {
			return self::unknownOptions();
		}
		if ( ! self::isInventoryRead( $route, $request->get_method() ) || ! self::authorize( $request ) ) {
			return self::notFound();
		}
		self::$verified = true;
		return $result;
	}

	private static function isInventoryRead( string $route, string $method ): bool {
		return self::FULL_ROUTE === $route && in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * Drops the namespace and its routes from the /wp-json/ index.
	 *
	 * @param \WP_REST_Response $response
	 * @return \WP_REST_Response
	 */
	public static function hideFromIndex( $response ) {
		$data = $response->get_data();
		if ( isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
			$data['namespaces'] = array_values( array_diff( $data['namespaces'], array( self::NAMESPACE ) ) );
		}
		if ( isset( $data['routes'] ) && is_array( $data['routes'] ) ) {
			foreach ( array_keys( $data['routes'] ) as $route ) {
				if ( str_starts_with( strtolower( (string) $route ), self::PREFIX ) ) {
					unset( $data['routes'][ $route ] );
				}
			}
		}
		$response->set_data( $data );
		return $response;
	}

	private static function authorize( \WP_REST_Request $request ): bool {
		$keys = Keys::configured();
		if ( array() === $keys ) {
			self::logRejection( 'no public key configured' );
			return false;
		}
		try {
			$key_id = ( new Verifier( $keys, new OptionNonceStore(), time() ) )->verify( self::headers( $request ), self::host() );
		} catch ( AuthFailure $e ) {
			self::logRejection( $e->getMessage() );
			return false;
		}
		update_option( self::LAST_PULL, array( 'time' => time(), 'key_id' => $key_id ), false );
		return true;
	}

	/** Rate-limited so unsigned noise cannot flood the error log. */
	private static function logRejection( string $reason ): void {
		if ( false !== get_transient( self::LOG_GATE ) ) {
			return;
		}
		set_transient( self::LOG_GATE, 1, self::LOG_INTERVAL );
		error_log( 'fc-inventory: rejected request: ' . $reason . ' (further rejections within 60 s are not logged)' );
	}

	/** @return array<string,?string> */
	private static function headers( \WP_REST_Request $request ): array {
		return array(
			'key_id'    => $request->get_header( 'X-FC-Key-Id' ),
			'timestamp' => $request->get_header( 'X-FC-Timestamp' ),
			'nonce'     => $request->get_header( 'X-FC-Nonce' ),
			'signature' => $request->get_header( 'X-FC-Signature' ),
		);
	}

	private static function host(): string {
		$http_host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		return Signature::normalizeHost( $http_host, is_ssl() );
	}

	/** The error WordPress returns for an unknown route (WP_REST_Server::match_request_to_handler). */
	public static function notFound(): \WP_Error {
		return new \WP_Error(
			'rest_no_route',
			__( 'No route was found matching the URL and request method.' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * What WordPress answers to OPTIONS on a route that does not exist
	 * (rest_handle_options_request without a match): 200 with an empty list.
	 */
	public static function unknownOptions(): \WP_REST_Response {
		$response = new \WP_REST_Response();
		$response->set_data( array() );
		return $response;
	}

	/** @return true|\WP_Error */
	public static function permission() {
		return self::$verified ? true : self::notFound();
	}

	public static function respond(): \WP_REST_Response {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		$response = new \WP_REST_Response( Inventory::fromWordPress(), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	public static function fallbackUrl(): string {
		return add_query_arg( 'rest_route', self::FULL_ROUTE, home_url( '/' ) );
	}
}
