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
