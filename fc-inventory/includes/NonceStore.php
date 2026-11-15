<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * Remembers nonces of verified requests so a captured request cannot be
 * replayed inside the timestamp window.
 */
interface NonceStore {
	public function seen( string $nonce ): bool;

	public function remember( string $nonce, int $ttl ): void;
}
