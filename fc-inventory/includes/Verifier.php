<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * Verifies one signed request (contract v1). Pure: keys, nonce store and
 * clock are passed in, so it runs without WordPress.
 */
final class Verifier {
	private const TIMESTAMP_RE = '/^[1-9][0-9]{0,11}$/D';
	/** 16 bytes in base64url without padding are 22 characters. */
	private const NONCE_RE = '/^[A-Za-z0-9_-]{22}$/D';
	/** 64 bytes in standard base64 are 86 characters plus "==". */
	private const SIGNATURE_RE = '/^[A-Za-z0-9+\/]{86}==$/D';

	/**
	 * @param array<string,string> $keys key_id => raw 32-byte public key
	 */
	public function __construct(
		private array $keys,
		private NonceStore $nonces,
		private int $now
	) {
	}

	/**
	 * @param array{key_id?: ?string, timestamp?: ?string, nonce?: ?string, signature?: ?string} $headers
	 * @param string $host normalized host (see Signature::normalizeHost)
	 * @return string the key id that signed the request
	 * @throws AuthFailure on any failed check
	 */
	public function verify( array $headers, string $host ): string {
		$key_id    = $this->keyId( (string) ( $headers['key_id'] ?? '' ) );
		$timestamp = $this->timestamp( (string) ( $headers['timestamp'] ?? '' ) );
		$nonce     = $this->nonce( (string) ( $headers['nonce'] ?? '' ) );
		$signature = $this->signature( (string) ( $headers['signature'] ?? '' ) );
		if ( '' === $host ) {
			throw new AuthFailure( 'missing host' );
		}
		$message = Signature::message( $host, Signature::CANONICAL_PATH, $timestamp, $nonce );
		if ( ! sodium_crypto_sign_verify_detached( $signature, $message, $this->keys[ $key_id ] ) ) {
			throw new AuthFailure( 'signature does not verify' );
		}
		$this->consumeNonce( $nonce );
		return $key_id;
	}

	private function keyId( string $key_id ): string {
		if ( ! Keys::validKeyId( $key_id ) ) {
			throw new AuthFailure( 'missing or malformed key id' );
		}
		if ( ! isset( $this->keys[ $key_id ] ) ) {
			throw new AuthFailure( 'unknown key id' );
		}
		return $key_id;
	}

	private function timestamp( string $timestamp ): string {
		if ( 1 !== preg_match( self::TIMESTAMP_RE, $timestamp ) ) {
			throw new AuthFailure( 'missing or malformed timestamp' );
		}
		if ( abs( $this->now - (int) $timestamp ) > Signature::WINDOW ) {
			throw new AuthFailure( 'timestamp outside window' );
		}
		return $timestamp;
	}

	private function nonce( string $nonce ): string {
		if ( 1 !== preg_match( self::NONCE_RE, $nonce ) ) {
			throw new AuthFailure( 'missing or malformed nonce' );
		}
		return $nonce;
	}

	private function signature( string $b64 ): string {
		$raw = 1 === preg_match( self::SIGNATURE_RE, $b64 ) ? base64_decode( $b64, true ) : false;
		if ( false === $raw || 64 !== strlen( $raw ) ) {
			throw new AuthFailure( 'missing or malformed signature' );
		}
		return $raw;
	}

	/** Checked after the signature so unsigned noise cannot fill the store. */
	private function consumeNonce( string $nonce ): void {
		if ( $this->nonces->seen( $nonce ) ) {
			throw new AuthFailure( 'replayed nonce' );
		}
		$this->nonces->remember( $nonce, Signature::NONCE_TTL );
	}
}
