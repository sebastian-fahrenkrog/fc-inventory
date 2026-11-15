<?php
namespace FcInventory\Tests;

use FcInventory\Manifest;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase {
	private string $secret;
	private string $public_b64;

	protected function setUp(): void {
		$pair             = sodium_crypto_sign_seed_keypair( str_repeat( "\x04", 32 ) );
		$this->secret     = sodium_crypto_sign_secretkey( $pair );
		$this->public_b64 = base64_encode( sodium_crypto_sign_publickey( $pair ) );
	}

	private function json( array $override = array() ): string {
		return (string) json_encode(
			array_merge(
				array(
					'version'      => '1.2.3',
					'zip_url'      => Manifest::ZIP_PREFIX . 'v1.2.3/fc-inventory.zip',
					'sha256'       => str_repeat( 'ab', 32 ),
					'requires'     => '6.4',
					'requires_php' => '8.1',
					'tested'       => '6.8',
					'changelog'    => 'Fixes.',
				),
				$override
			)
		);
	}

	private function sign( string $json ): string {
		return base64_encode( sodium_crypto_sign_detached( $json, $this->secret ) );
	}

	public function testValidManifest(): void {
		$json = $this->json();
		$m    = Manifest::parse( $json, $this->sign( $json ) . "\n", $this->public_b64 );
		$this->assertSame( '1.2.3', $m['version'] ?? null );
		$this->assertSame( '8.1', $m['requires_php'] );
	}

	public function testTamperedManifest(): void {
		$sig = $this->sign( $this->json() );
		$this->assertNull( Manifest::parse( $this->json( array( 'version' => '9.9.9' ) ), $sig, $this->public_b64 ) );
	}

	public function testWrongKey(): void {
		$json = $this->json();
		$this->assertNull( Manifest::parse( $json, $this->sign( $json ) ) );
	}

	public function testForeignZipUrl(): void {
		$json = $this->json( array( 'zip_url' => 'https://evil.example/fc-inventory.zip' ) );
		$this->assertNull( Manifest::parse( $json, $this->sign( $json ), $this->public_b64 ) );
	}

	public function testBadSha256(): void {
		$json = $this->json( array( 'sha256' => 'xyz' ) );
		$this->assertNull( Manifest::parse( $json, $this->sign( $json ), $this->public_b64 ) );
	}

	public function testCompiledReleaseKeyIsValid(): void {
		$this->assertNotNull( \FcInventory\Keys::decodePublicKey( Manifest::RELEASE_PUBKEY ) );
	}
}
