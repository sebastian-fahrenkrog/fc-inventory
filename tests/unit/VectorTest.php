<?php
namespace FcInventory\Tests;

use FcInventory\Signature;
use FcInventory\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * The shared vector tests/vectors/signature-v1.json (also used by the Go
 * puller) must match this implementation byte for byte.
 */
final class VectorTest extends TestCase {
	private static function vector(): array {
		$json = file_get_contents( dirname( __DIR__ ) . '/vectors/signature-v1.json' );
		return json_decode( (string) $json, true, 16, JSON_THROW_ON_ERROR );
	}

	public function testKeyPairFromSeed(): void {
		$v    = self::vector();
		$pair = sodium_crypto_sign_seed_keypair( hex2bin( $v['seed_hex'] ) );
		$this->assertSame( str_repeat( '01', 32 ), $v['seed_hex'] );
		$this->assertSame( $v['public_key'], base64_encode( sodium_crypto_sign_publickey( $pair ) ) );
	}

	public function testMessageAndSignature(): void {
		$v       = self::vector();
		$nonce   = rtrim( strtr( base64_encode( hex2bin( $v['nonce_hex'] ) ), '+/', '-_' ), '=' );
		$message = Signature::message( $v['host'], $v['path'], (string) $v['timestamp'], $v['nonce'] );
		$secret  = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( hex2bin( $v['seed_hex'] ) ) );
		$this->assertSame( $v['nonce'], $nonce );
		$this->assertSame( Signature::CANONICAL_PATH, $v['path'] );
		$this->assertSame( $v['message'], $message );
		$this->assertSame( $v['message_hex'], bin2hex( $message ) );
		$this->assertSame( $v['signature'], base64_encode( sodium_crypto_sign_detached( $message, $secret ) ) );
	}

	public function testVerifierAcceptsVector(): void {
		$v        = self::vector();
		$verifier = new Verifier( array( $v['key_id'] => base64_decode( $v['public_key'] ) ), new ArrayNonceStore(), $v['timestamp'] );
		$headers  = array(
			'key_id'    => $v['key_id'],
			'timestamp' => (string) $v['timestamp'],
			'nonce'     => $v['nonce'],
			'signature' => $v['signature'],
		);
		$this->assertSame( $v['key_id'], $verifier->verify( $headers, $v['host'] ) );
	}

	public function testHostExamples(): void {
		foreach ( self::vector()['host_examples'] as $case ) {
			$this->assertSame( $case['host'], Signature::normalizeHost( $case['http_host'], $case['https'] ), $case['http_host'] );
		}
	}
}
