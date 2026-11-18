<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * The signed-message format of contract v1, shared by plugin and puller.
 */
final class Signature {
	public const PREFIX = 'fc-inventory-v1';
	public const METHOD = 'GET';

	/** Both URL forms (/wp-json/ and ?rest_route=) are signed with this path. */
	public const CANONICAL_PATH = '/wp-json/fc-inventory/v1/inventory';

	/** Action requests (contract action-v1) use their own prefix, method and path. */
	public const ACTION_PREFIX = 'fc-inventory-action-v1';
	public const ACTION_METHOD = 'POST';
	public const ACTION_PATH   = '/wp-json/fc-inventory/v1/action';

	/** Accepted clock skew in seconds, both directions. */
	public const WINDOW = 300;

	/** Lifetime of a remembered nonce; covers the whole window twice. */
	public const NONCE_TTL = 600;

	/**
	 * Builds the message: fields joined by "\n", no trailing newline.
	 */
	public static function message( string $host, string $path, string $timestamp, string $nonce ): string {
		return implode( "\n", array( self::PREFIX, self::METHOD, $host, $path, $timestamp, $nonce ) );
	}

	/**
	 * Message of an action request: like message(), plus the SHA-256 of the
	 * raw request body (lowercase hex), so the signed order cannot be altered.
	 */
	public static function actionMessage( string $host, string $timestamp, string $nonce, string $body ): string {
		$fields = array( self::ACTION_PREFIX, self::ACTION_METHOD, $host, self::ACTION_PATH, $timestamp, $nonce, hash( 'sha256', $body ) );
		return implode( "\n", $fields );
	}

	/**
	 * Lowercases the Host header and strips the default port of the scheme.
	 */
	public static function normalizeHost( string $http_host, bool $https ): string {
		$host    = strtolower( trim( $http_host ) );
		$default = $https ? ':443' : ':80';
		if ( str_ends_with( $host, $default ) ) {
			$host = substr( $host, 0, -strlen( $default ) );
		}
		return $host;
	}
}
