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

	/** Every case, including the top-level one, must reproduce byte for byte. */
	public function testMessageAndSignature(): void {
		$v      = self::vector();
		$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( hex2bin( $v['seed_hex'] ) ) );
		$this->assertSame( Signature::CANONICAL_PATH, $v['path'] );
		$this->assertCount( 2, $v['cases'] );
		foreach ( array_merge( array( $v ), $v['cases'] ) as $case ) {
			$nonce   = rtrim( strtr( base64_encode( hex2bin( $case['nonce_hex'] ) ), '+/', '-_' ), '=' );
			$message = Signature::message( $v['host'], $v['path'], (string) $v['timestamp'], $case['nonce'] );
			$this->assertSame( $case['nonce'], $nonce );
			$this->assertSame( $case['message'], $message );
			$this->assertSame( $case['message_hex'], bin2hex( $message ) );
			$this->assertSame( $case['signature'], base64_encode( sodium_crypto_sign_detached( $message, $secret ) ) );
		}
	}

	/** The case the Go puller computed independently. */
	public function testGoPullerCase(): void {
		$go = array_column( self::vector()['cases'], null, 'name' )['nonce-01'];
		$this->assertSame( 'AQEBAQEBAQEBAQEBAQEBAQ', $go['nonce'] );
		$this->assertSame( 'chy5/hQznKCpGwZxJU4W8lQnpemxRftbnIVl3DQ6uq+dFd+dg2/NRi5vb3uCU7diQehaWni+C5YDgUMvdoRxAQ==', $go['signature'] );
	}

	public function testVerifierAcceptsEveryCase(): void {
		$v = self::vector();
		foreach ( $v['cases'] as $case ) {
			$verifier = new Verifier( array( $v['key_id'] => base64_decode( $v['public_key'] ) ), new ArrayNonceStore(), $v['timestamp'] );
			$headers  = array(
				'key_id'    => $v['key_id'],
				'timestamp' => (string) $v['timestamp'],
				'nonce'     => $case['nonce'],
				'signature' => $case['signature'],
			);
			$this->assertSame( $v['key_id'], $verifier->verify( $headers, $v['host'] ), $case['name'] );
		}
	}

	public function testHostExamples(): void {
		foreach ( self::vector()['host_examples'] as $case ) {
			$this->assertSame( $case['host'], Signature::normalizeHost( $case['http_host'], $case['https'] ), $case['http_host'] );
		}
	}
}
