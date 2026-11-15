<?php
namespace FcInventory\Tests;

use FcInventory\AuthFailure;
use FcInventory\Signature;
use FcInventory\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Signature checks of contract v1. Every failure must throw AuthFailure,
 * which the endpoint turns into the plain 404.
 */
final class VerifierTest extends TestCase {
	private const NOW    = 1794729600;
	private const HOST   = 'www.example.com';
	private const KEY_ID = '2026-11';

	private string $secret;
	private string $public;
	private ArrayNonceStore $store;

	protected function setUp(): void {
		$pair         = sodium_crypto_sign_seed_keypair( str_repeat( "\x02", 32 ) );
		$this->secret = sodium_crypto_sign_secretkey( $pair );
		$this->public = sodium_crypto_sign_publickey( $pair );
		$this->store  = new ArrayNonceStore();
	}

	private function verifier( int $now = self::NOW ): Verifier {
		return new Verifier( array( self::KEY_ID => $this->public ), $this->store, $now );
	}

	/** Signed headers as the puller sends them. */
	private function headers( int $timestamp = self::NOW, string $host = self::HOST, ?string $nonce = null ): array {
		$nonce   = $nonce ?? rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
		$message = Signature::message( $host, Signature::CANONICAL_PATH, (string) $timestamp, $nonce );
		return array(
			'key_id'    => self::KEY_ID,
			'timestamp' => (string) $timestamp,
			'nonce'     => $nonce,
			'signature' => base64_encode( sodium_crypto_sign_detached( $message, $this->secret ) ),
		);
	}

	private function assertRejected( array $headers, string $reason, ?Verifier $verifier = null ): void {
		try {
			( $verifier ?? $this->verifier() )->verify( $headers, self::HOST );
			$this->fail( 'request was accepted' );
		} catch ( AuthFailure $e ) {
			$this->assertSame( $reason, $e->getMessage() );
		}
	}

	public function testValidRequestReturnsKeyId(): void {
		$headers = $this->headers();
		$this->assertSame( self::KEY_ID, $this->verifier()->verify( $headers, self::HOST ) );
		$this->assertArrayHasKey( $headers['nonce'], $this->store->nonces );
		$this->assertSame( Signature::NONCE_TTL, $this->store->nonces[ $headers['nonce'] ] );
	}

	public function testEdgeOfWindowIsAccepted(): void {
		$this->assertSame( self::KEY_ID, $this->verifier()->verify( $this->headers( self::NOW - 300 ), self::HOST ) );
		$this->assertSame( self::KEY_ID, $this->verifier()->verify( $this->headers( self::NOW + 300 ), self::HOST ) );
	}

	public function testExpiredTimestamp(): void {
		$this->assertRejected( $this->headers( self::NOW - 301 ), 'timestamp outside window' );
	}

	public function testFutureTimestamp(): void {
		$this->assertRejected( $this->headers( self::NOW + 301 ), 'timestamp outside window' );
	}

	public function testReplayedNonce(): void {
		$headers = $this->headers();
		$this->verifier()->verify( $headers, self::HOST );
		$this->assertRejected( $headers, 'replayed nonce' );
	}

	public function testWrongHost(): void {
		$this->assertRejected( $this->headers( self::NOW, 'other.example.com' ), 'signature does not verify' );
	}

	public function testUnknownKeyId(): void {
		$headers           = $this->headers();
		$headers['key_id'] = '2027-01';
		$this->assertRejected( $headers, 'unknown key id' );
	}

	public function testMalformedKeyId(): void {
		$headers           = $this->headers();
		$headers['key_id'] = 'bad key/id';
		$this->assertRejected( $headers, 'missing or malformed key id' );
	}

	public function testBadBase64Signature(): void {
		$headers              = $this->headers();
		$headers['signature'] = str_repeat( '!', 86 ) . '==';
		$this->assertRejected( $headers, 'missing or malformed signature' );
	}

	public function testShortSignature(): void {
		$headers              = $this->headers();
		$headers['signature'] = base64_encode( random_bytes( 32 ) );
		$this->assertRejected( $headers, 'missing or malformed signature' );
	}

	public function testWrongSignature(): void {
		$headers              = $this->headers();
		$headers['signature'] = base64_encode( str_repeat( "\x00", 64 ) );
		$this->assertRejected( $headers, 'signature does not verify' );
	}

	public function testSignatureByOtherKey(): void {
		$headers              = $this->headers();
		$other                = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( str_repeat( "\x03", 32 ) ) );
		$message              = Signature::message( self::HOST, Signature::CANONICAL_PATH, $headers['timestamp'], $headers['nonce'] );
		$headers['signature'] = base64_encode( sodium_crypto_sign_detached( $message, $other ) );
		$this->assertRejected( $headers, 'signature does not verify' );
	}

	public function testMalformedNonce(): void {
		$headers          = $this->headers();
		$headers['nonce'] = 'too-short';
		$this->assertRejected( $headers, 'missing or malformed nonce' );
	}

	public function testMalformedTimestamp(): void {
		$headers              = $this->headers();
		$headers['timestamp'] = '0' . self::NOW;
		$this->assertRejected( $headers, 'missing or malformed timestamp' );
	}

	public function testMissingHeaders(): void {
		$this->assertRejected( array(), 'missing or malformed key id' );
	}

	public function testFailedRequestDoesNotStoreNonce(): void {
		$headers              = $this->headers();
		$headers['signature'] = base64_encode( str_repeat( "\x00", 64 ) );
		$this->assertRejected( $headers, 'signature does not verify' );
		$this->assertSame( array(), $this->store->nonces );
	}

	public function testHostNormalization(): void {
		$this->assertSame( 'www.example.com', Signature::normalizeHost( 'WWW.Example.com:443', true ) );
		$this->assertSame( 'www.example.com', Signature::normalizeHost( 'www.example.com:80', false ) );
		$this->assertSame( 'www.example.com:80', Signature::normalizeHost( 'www.example.com:80', true ) );
		$this->assertSame( 'www.example.com:8080', Signature::normalizeHost( 'www.example.com:8080', false ) );
	}
}
