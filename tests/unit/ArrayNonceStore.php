<?php
namespace FcInventory\Tests;

use FcInventory\NonceStore;

/** In-memory nonce store for tests. */
final class ArrayNonceStore implements NonceStore {
	/** @var array<string,int> */
	public array $nonces = array();

	public function seen( string $nonce ): bool {
		return isset( $this->nonces[ $nonce ] );
	}

	public function remember( string $nonce, int $ttl ): bool {
		if ( isset( $this->nonces[ $nonce ] ) ) {
			return false;
		}
		$this->nonces[ $nonce ] = $ttl;
		return true;
	}
}
