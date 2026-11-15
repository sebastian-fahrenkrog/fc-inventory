<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * REST route fc-inventory/v1/inventory.
 *
 * The signature is checked in rest_pre_dispatch, before WordPress matches
 * the route. A failure returns the same WP_Error that WordPress builds for
 * an unknown route, and because no route is matched yet, no Allow header or
 * other route-specific output is added: the 404 is byte-identical to the one
 * for a route that does not exist. The permission_callback only confirms
 * that this request passed that check.
 */
final class Endpoint {
	public const NAMESPACE  = 'fc-inventory/v1';
	public const ROUTE      = '/inventory';
	public const LAST_PULL  = 'fc_inventory_last_pull';
	private const FULL_ROUTE = '/' . self::NAMESPACE . self::ROUTE;

	private static bool $verified = false;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoute' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'preDispatch' ), -100, 3 );
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
		if ( null !== $result || ! self::sodiumAvailable() || ! self::isOurRequest( $request ) ) {
			return $result;
		}
		if ( ! self::authorize( $request ) ) {
			return self::notFound();
		}
		self::$verified = true;
		return $result;
	}

	/** Same match rules as WordPress: case-insensitive, GET and HEAD. */
	private static function isOurRequest( \WP_REST_Request $request ): bool {
		$route = untrailingslashit( strtolower( $request->get_route() ) );
		return self::FULL_ROUTE === $route && in_array( $request->get_method(), array( 'GET', 'HEAD' ), true );
	}

	private static function authorize( \WP_REST_Request $request ): bool {
		if ( Throttle::blocked() ) {
			error_log( 'fc-inventory: rejected request: throttled' );
			return false;
		}
		$keys = Keys::configured();
		if ( array() === $keys ) {
			error_log( 'fc-inventory: rejected request: no public key configured' );
			return false;
		}
		try {
			$key_id = ( new Verifier( $keys, new TransientNonceStore(), time() ) )->verify( self::headers( $request ), self::host() );
		} catch ( AuthFailure $e ) {
			error_log( 'fc-inventory: rejected request: ' . $e->getMessage() );
			Throttle::recordFailure( time() );
			return false;
		}
		update_option( self::LAST_PULL, array( 'time' => time(), 'key_id' => $key_id ), false );
		return true;
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
