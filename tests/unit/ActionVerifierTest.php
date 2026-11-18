<?php
namespace FcInventory\Tests;

use FcInventory\AuthFailure;
use FcInventory\Signature;
use FcInventory\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Signature checks of contract action-v1 against tests/vectors/action-v1.json
 * (shared with the Python client).
 */
final class ActionVerifierTest extends TestCase {
	private static function vector(): array {
		$json = file_get_contents( dirname( __DIR__ ) . '/vectors/action-v1.json' );
		return json_decode( (string) $json, true, 16, JSON_THROW_ON_ERROR );
	}

	private static function verifier( array $v ): Verifier {
		return new Verifier( array( $v['key_id'] => base64_decode( $v['public_key'] ) ), new ArrayNonceStore(), $v['timestamp'] );
	}

	private static function headers( array $v, array $case ): array {
		return array(
			'key_id'    => $v['key_id'],
			'timestamp' => (string) $v['timestamp'],
			'nonce'     => $case['nonce'],
			'signature' => $case['signature'],
		);
	}

	public function testVectorReproducesByteForByte(): void {
		$v      = self::vector();
		$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( hex2bin( $v['seed_hex'] ) ) );
		$this->assertSame( array( 'fc-inventory-action-v1', 'POST', '/wp-json/fc-inventory/v1/action' ), array( $v['prefix'], $v['method'], $v['path'] ) );
		foreach ( $v['cases'] as $case ) {
			$message = Signature::actionMessage( $v['host'], (string) $v['timestamp'], $case['nonce'], $case['body'] );
			$this->assertSame( $case['message'], $message, $case['name'] );
			$this->assertStringEndsWith( "\n" . $case['body_sha256'], $message );
			$this->assertSame( $case['signature'], base64_encode( sodium_crypto_sign_detached( $message, $secret ) ) );
		}
	}

	public function testAcceptsEveryCase(): void {
		$v = self::vector();
		foreach ( $v['cases'] as $case ) {
			$this->assertSame( $v['key_id'], self::verifier( $v )->verifyAction( self::headers( $v, $case ), $v['host'], $case['body'] ) );
		}
	}

	private function assertRejected( callable $call, string $reason ): void {
		try {
			$call();
			$this->fail( 'request was accepted' );
		} catch ( AuthFailure $e ) {
			$this->assertSame( $reason, $e->getMessage() );
		}
	}

	public function testChangedBodyIsRejected(): void {
		$v    = self::vector();
		$case = $v['cases'][0];
		$body = str_replace( 'akismet', 'elementor', $case['body'] );
		$this->assertRejected( fn() => self::verifier( $v )->verifyAction( self::headers( $v, $case ), $v['host'], $body ), 'signature does not verify' );
	}

	public function testOtherHostIsRejected(): void {
		$v = self::vector();
		$this->assertRejected( fn() => self::verifier( $v )->verifyAction( self::headers( $v, $v['cases'][0] ), 'other.example.com', $v['cases'][0]['body'] ), 'signature does not verify' );
	}

	/** An action signature must not open the inventory, and an inventory signature must not order anything. */
	public function testSignaturesDoNotCrossRoutes(): void {
		$v    = self::vector();
		$case = $v['cases'][0];
		$this->assertRejected( fn() => self::verifier( $v )->verify( self::headers( $v, $case ), $v['host'] ), 'signature does not verify' );

		$secret  = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( hex2bin( $v['seed_hex'] ) ) );
		$message = Signature::message( $v['host'], Signature::CANONICAL_PATH, (string) $v['timestamp'], $case['nonce'] );
		$headers = array( 'signature' => base64_encode( sodium_crypto_sign_detached( $message, $secret ) ) ) + self::headers( $v, $case );
		$this->assertRejected( fn() => self::verifier( $v )->verifyAction( $headers, $v['host'], $case['body'] ), 'signature does not verify' );
	}

	public function testReplayIsRejected(): void {
		$v        = self::vector();
		$case     = $v['cases'][0];
		$verifier = self::verifier( $v );
		$verifier->verifyAction( self::headers( $v, $case ), $v['host'], $case['body'] );
		$this->assertRejected( fn() => $verifier->verifyAction( self::headers( $v, $case ), $v['host'], $case['body'] ), 'replayed nonce' );
	}
}
