<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * Self-check and local inventory dump.
 */
final class Cli {
	/**
	 * Checks sodium and keys, shows the endpoint URLs and prints the inventory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fc-inventory check
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function check( $args, $assoc_args ): void {
		$problems = 0;
		$problems += self::line( Endpoint::sodiumAvailable(), 'ext-sodium available', 'ext-sodium missing: the endpoint answers 404' );
		$keys      = Keys::configured();
		$source    = Keys::fromConstant() ? 'constant FC_INVENTORY_PUBKEYS' : 'option ' . Keys::OPTION;
		$problems += self::line( array() !== $keys, sprintf( 'keys (%s): %s', $source, implode( ', ', array_keys( $keys ) ) ), sprintf( 'no valid key configured (%s)', $source ) );
		$actions = array_keys( Keys::configuredActions() );
		\WP_CLI::log( 'Action keys:  ' . ( array() === $actions ? 'none (updates cannot be ordered)' : implode( ', ', $actions ) . ' → ' . Endpoint::actionUrl() ) );
		\WP_CLI::log( 'Endpoint:     ' . Endpoint::url() );
		\WP_CLI::log( 'Fallback URL: ' . Endpoint::fallbackUrl() );
		\WP_CLI::log( 'Inventory (local dry run):' );
		\WP_CLI::log( self::json() );
		if ( $problems > 0 ) {
			\WP_CLI::error( sprintf( '%d problem(s) found.', $problems ) );
		}
		\WP_CLI::success( 'fc-inventory is ready.' );
	}

	/**
	 * Prints the inventory JSON (same content as the endpoint, pretty-printed), nothing else.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fc-inventory inventory
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function inventory( $args, $assoc_args ): void {
		\WP_CLI::line( self::json() );
	}

	private static function json(): string {
		return (string) wp_json_encode( Inventory::fromWordPress(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/** Logs an ok/problem line and returns 1 for a problem. */
	private static function line( bool $ok, string $good, string $bad ): int {
		if ( $ok ) {
			\WP_CLI::log( 'OK      ' . $good );
			return 0;
		}
		\WP_CLI::warning( $bad );
		return 1;
	}
}
