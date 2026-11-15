<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce store on WordPress transients (database or object cache).
 */
final class TransientNonceStore implements NonceStore {
	public function seen( string $nonce ): bool {
		return false !== get_transient( self::key( $nonce ) );
	}

	public function remember( string $nonce, int $ttl ): void {
		set_transient( self::key( $nonce ), 1, $ttl );
	}

	private static function key( string $nonce ): string {
		return 'fc_inv_nonce_' . hash( 'sha256', $nonce );
	}
}
