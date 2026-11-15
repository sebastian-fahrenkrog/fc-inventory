<?php
namespace FcInventory\Tests;

use FcInventory\Keys;
use PHPUnit\Framework\TestCase;

final class KeysTest extends TestCase {
	private const KEY = 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=';

	public function testKeyIdCharset(): void {
		$this->assertTrue( Keys::validKeyId( '2026-11' ) );
		$this->assertTrue( Keys::validKeyId( 'a.b_c-D9' ) );
		$this->assertTrue( Keys::validKeyId( str_repeat( 'a', 32 ) ) );
		$this->assertFalse( Keys::validKeyId( '' ) );
		$this->assertFalse( Keys::validKeyId( str_repeat( 'a', 33 ) ) );
		$this->assertFalse( Keys::validKeyId( "2026-11\n" ) );
		$this->assertFalse( Keys::validKeyId( 'a b' ) );
		$this->assertFalse( Keys::validKeyId( 'ä' ) );
	}

	public function testDecodePublicKey(): void {
		$this->assertSame( 32, strlen( (string) Keys::decodePublicKey( self::KEY ) ) );
		$this->assertNull( Keys::decodePublicKey( base64_encode( random_bytes( 31 ) ) ) );
		$this->assertNull( Keys::decodePublicKey( base64_encode( random_bytes( 33 ) ) ) );
		$this->assertNull( Keys::decodePublicKey( 'not base64 at all' ) );
		$this->assertNull( Keys::decodePublicKey( strtr( self::KEY, '+/', '-_' ) . 'x' ) );
	}

	public function testParseMap(): void {
		list( $keys, $errors ) = Keys::parseMap( array( '2026-11' => self::KEY, 'bad id' => self::KEY, 'x' => 'short' ) );
		$this->assertSame( array( '2026-11' ), array_keys( $keys ) );
		$this->assertCount( 2, $errors );
	}

	public function testParseText(): void {
		list( $map, $errors ) = Keys::parseText( "# comment\n2026-11 " . self::KEY . "\r\n\n2027-01   " . self::KEY . "\n" );
		$this->assertSame( array(), $errors );
		$this->assertSame( array( '2026-11' => self::KEY, '2027-01' => self::KEY ), $map );
	}

	public function testParseTextRejectsBadLines(): void {
		list( , $errors ) = Keys::parseText( "only-one-field\n2026-11 " . self::KEY . "\n2026-11 " . self::KEY );
		$this->assertCount( 2, $errors );
	}
}
