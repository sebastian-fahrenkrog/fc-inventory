<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * Remembers nonces of verified requests so a captured request cannot be
 * replayed inside the timestamp window.
 */
interface NonceStore {
	/** Whether the nonce is stored (diagnostics; the check itself uses remember()). */
	public function seen( string $nonce ): bool;

	/**
	 * Stores the nonce atomically. Returns false if it was already stored or
	 * could not be stored; the caller treats both as a replay.
	 */
	public function remember( string $nonce, int $ttl ): bool;
}
