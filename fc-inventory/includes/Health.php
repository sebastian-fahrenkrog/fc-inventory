<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * Loopback probes of the site before and after a change. A change is rolled
 * back only on a clear regression: a URL that answered below 500 before and
 * answers 5xx (or not at all) afterwards. Where loopback requests are
 * blocked, the probes report that and no automatic rollback happens.
 */
final class Health {
	private const TIMEOUT = 20;

	/** @return array<string,int> probe name => HTTP status, 0 if the request failed */
	public static function probe(): array {
		$urls = array(
			'home'  => add_query_arg( 'fc_health', wp_generate_password( 12, false ), home_url( '/' ) ),
			'login' => wp_login_url(),
		);
		return array_map( array( self::class, 'status' ), $urls );
	}

	private static function status( string $url ): int {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/** Whether the probes worked at all (a host may block loopback requests). */
	public static function usable( array $before ): bool {
		return array() !== array_filter( $before, static fn( int $code ): bool => $code > 0 && $code < 500 );
	}

	/**
	 * @param array<string,int> $before
	 * @param array<string,int> $after
	 */
	public static function regressed( array $before, array $after ): bool {
		foreach ( $before as $name => $code ) {
			$now = $after[ $name ] ?? 0;
			if ( $code > 0 && $code < 500 && ( 0 === $now || $now >= 500 ) ) {
				return true;
			}
		}
		return false;
	}
}
