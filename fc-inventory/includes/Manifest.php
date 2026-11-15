<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * The signed release manifest (fc-inventory.json + fc-inventory.json.sig).
 * Pure: verifies the detached signature over the raw manifest bytes and
 * validates the fields. Nothing in an unsigned or malformed manifest is used.
 */
final class Manifest {
	/** Release public key (Ed25519, base64). Separate from the puller keys. */
	public const RELEASE_PUBKEY = 'pKtSQRpUwYiCQ7Wn1tcrGuBA3mCIn+QEfnuc6N5BHZk=';

	public const REPO_URL    = 'https://github.com/sebastian-fahrenkrog/fc-inventory';
	public const LATEST_URL  = self::REPO_URL . '/releases/latest/download/';
	public const ZIP_PREFIX  = self::REPO_URL . '/releases/download/';
	public const MANIFEST    = 'fc-inventory.json';
	public const SIGNATURE   = 'fc-inventory.json.sig';
	public const MAX_BYTES   = 65536;

	private const VERSION_RE = '/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$/D';
	private const SHA256_RE  = '/^[0-9a-f]{64}$/D';
	private const OPTIONAL   = array( 'requires', 'requires_php', 'tested', 'changelog' );

	/**
	 * Returns the validated manifest, or null if the signature or any field is bad.
	 *
	 * @return array{version: string, zip_url: string, sha256: string, requires: string, requires_php: string, tested: string, changelog: string}|null
	 */
	public static function parse( string $json, string $signature_b64, string $pubkey_b64 = self::RELEASE_PUBKEY ): ?array {
		if ( ! self::signatureValid( $json, trim( $signature_b64 ), $pubkey_b64 ) ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? self::fields( $data ) : null;
	}

	public static function signatureValid( string $json, string $signature_b64, string $pubkey_b64 ): bool {
		$sig = base64_decode( $signature_b64, true );
		$key = Keys::decodePublicKey( $pubkey_b64 );
		if ( false === $sig || 64 !== strlen( $sig ) || null === $key || strlen( $json ) > self::MAX_BYTES ) {
			return false;
		}
		return sodium_crypto_sign_verify_detached( $sig, $json, $key );
	}

	/**
	 * @param array<mixed> $data
	 */
	private static function fields( array $data ): ?array {
		$version = $data['version'] ?? null;
		$zip_url = $data['zip_url'] ?? null;
		$sha256  = $data['sha256'] ?? null;
		if ( ! is_string( $version ) || 1 !== preg_match( self::VERSION_RE, $version ) ) {
			return null;
		}
		if ( ! is_string( $zip_url ) || ! str_starts_with( $zip_url, self::ZIP_PREFIX ) ) {
			return null;
		}
		if ( ! is_string( $sha256 ) || 1 !== preg_match( self::SHA256_RE, $sha256 ) ) {
			return null;
		}
		$out = array( 'version' => $version, 'zip_url' => $zip_url, 'sha256' => $sha256 );
		foreach ( self::OPTIONAL as $field ) {
			$out[ $field ] = is_string( $data[ $field ] ?? null ) ? $data[ $field ] : '';
		}
		return $out;
	}
}
