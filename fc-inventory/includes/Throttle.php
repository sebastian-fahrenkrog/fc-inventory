<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide failure throttle: after LIMIT failed checks within WINDOW
 * seconds, every request is rejected without verifying for BLOCK seconds,
 * so the endpoint does not invite CPU-burning signature guessing.
 */
final class Throttle {
	public const LIMIT  = 10;
	public const WINDOW = 600;
	public const BLOCK  = 600;

	private const FAILS_KEY = 'fc_inventory_fails';
	private const BLOCK_KEY = 'fc_inventory_block';

	public static function blocked(): bool {
		return false !== get_transient( self::BLOCK_KEY );
	}

	public static function recordFailure( int $now ): void {
		$state = get_transient( self::FAILS_KEY );
		if ( ! is_array( $state ) || $now - (int) $state['start'] >= self::WINDOW ) {
			$state = array( 'n' => 0, 'start' => $now );
		}
		++$state['n'];
		if ( $state['n'] >= self::LIMIT ) {
			set_transient( self::BLOCK_KEY, 1, self::BLOCK );
			delete_transient( self::FAILS_KEY );
			error_log( 'fc-inventory: too many failed requests, endpoint blocked for 10 minutes' );
			return;
		}
		set_transient( self::FAILS_KEY, $state, max( 1, self::WINDOW - ( $now - $state['start'] ) ) );
	}
}
