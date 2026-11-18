<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * Puller public keys: validation and lookup. The constant
 * FC_INVENTORY_PUBKEYS wins over the option edited on Tools → FC Inventory.
 */
final class Keys {
	public const OPTION   = 'fc_inventory_pubkeys';
	public const CONSTANT = 'FC_INVENTORY_PUBKEYS';

	/**
	 * Keys that may order changes (updates, deactivation). Constant only, on
	 * purpose: nobody can grant write access from wp-admin, and a puller key
	 * never doubles as an action key.
	 */
	public const ACTION_CONSTANT = 'FC_INVENTORY_ACTION_KEYS';

	/** 32 bytes in standard base64 are exactly 43 characters plus one '='. */
	private const PUBKEY_RE = '/^[A-Za-z0-9+\/]{43}=$/D';
	private const KEY_ID_RE = '/^[A-Za-z0-9._-]{1,32}$/D';

	public static function validKeyId( string $key_id ): bool {
		return 1 === preg_match( self::KEY_ID_RE, $key_id );
	}

	/**
	 * Returns the raw 32-byte key, or null if the input is not exactly that.
	 */
	public static function decodePublicKey( string $b64 ): ?string {
		if ( 1 !== preg_match( self::PUBKEY_RE, $b64 ) ) {
			return null;
		}
		$raw = base64_decode( $b64, true );
		return ( false !== $raw && 32 === strlen( $raw ) ) ? $raw : null;
	}

	/**
	 * Validates a map key_id => base64 key.
	 *
	 * @param array<mixed,mixed> $map
	 * @return array{0: array<string,string>, 1: list<string>} raw keys and error messages
	 */
	public static function parseMap( array $map ): array {
		$keys   = array();
		$errors = array();
		foreach ( $map as $id => $b64 ) {
			$id    = (string) $id;
			$error = self::entryError( $id, is_string( $b64 ) ? trim( $b64 ) : '' );
			if ( null !== $error ) {
				$errors[] = $error;
				continue;
			}
			$keys[ $id ] = self::decodePublicKey( trim( $b64 ) );
		}
		return array( $keys, $errors );
	}

	/**
	 * Parses the admin textarea: one "<key-id> <base64>" per line, '#' starts a comment.
	 *
	 * @return array{0: array<string,string>, 1: list<string>} base64 keys and error messages
	 */
	public static function parseText( string $text ): array {
		$map    = array();
		$errors = array();
		foreach ( preg_split( '/\R/', $text ) as $n => $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $line );
			if ( 2 !== count( $parts ) ) {
				$errors[] = sprintf( 'Line %d: expected "<key-id> <base64 public key>".', $n + 1 );
				continue;
			}
			if ( isset( $map[ $parts[0] ] ) ) {
				$errors[] = sprintf( 'Line %d: duplicate key id.', $n + 1 );
				continue;
			}
			$map[ $parts[0] ] = $parts[1];
		}
		$errors = array_merge( $errors, self::parseMap( $map )[1] );
		return array( $map, $errors );
	}

	private static function entryError( string $id, string $b64 ): ?string {
		if ( ! self::validKeyId( $id ) ) {
			return 'Invalid key id (allowed: A-Z a-z 0-9 . _ -, 1 to 32 characters).';
		}
		if ( null === self::decodePublicKey( $b64 ) ) {
			return sprintf( 'Key "%s": not a 32-byte public key in standard base64.', $id );
		}
		return null;
	}

	public static function fromConstant(): bool {
		return defined( self::CONSTANT );
	}

	/**
	 * Configured keys as key_id => raw 32-byte key. Invalid entries are
	 * dropped and logged.
	 *
	 * @return array<string,string>
	 */
	public static function configured(): array {
		$map = self::fromConstant() ? constant( self::CONSTANT ) : get_option( self::OPTION, array() );
		return self::validated( $map, 'key' );
	}

	/**
	 * Action keys as key_id => raw 32-byte key; empty unless the constant
	 * FC_INVENTORY_ACTION_KEYS is defined.
	 *
	 * @return array<string,string>
	 */
	public static function configuredActions(): array {
		return defined( self::ACTION_CONSTANT ) ? self::validated( constant( self::ACTION_CONSTANT ), 'action key' ) : array();
	}

	/**
	 * @param mixed $map
	 * @return array<string,string>
	 */
	private static function validated( $map, string $label ): array {
		if ( ! is_array( $map ) ) {
			error_log( 'fc-inventory: ' . $label . ' configuration is not an array' );
			return array();
		}
		list( $keys, $errors ) = self::parseMap( $map );
		foreach ( $errors as $error ) {
			error_log( 'fc-inventory: ignoring ' . $label . ': ' . $error );
		}
		return $keys;
	}
}
