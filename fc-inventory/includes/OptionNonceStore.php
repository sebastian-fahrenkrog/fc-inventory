<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce store on wp_options, one row per nonce, value = expiry time.
 *
 * Storing is a single INSERT IGNORE on the unique option_name: of two
 * parallel requests with the same nonce exactly one inserts the row. This
 * deliberately bypasses add_option(), whose INSERT ... ON DUPLICATE KEY UPDATE
 * succeeds for both, and transients or an object cache, which can evict
 * entries. Rows are never autoloaded; expired ones are deleted after each
 * successful insert.
 */
final class OptionNonceStore implements NonceStore {
	public const PREFIX = 'fc_inventory_nonce_';

	private const CLEANUP_BATCH = 100;

	public function seen( string $nonce ): bool {
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name = %s", self::name( $nonce ) );
		return null !== $wpdb->get_var( $sql );
	}

	public function remember( string $nonce, int $ttl ): bool {
		global $wpdb;
		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
			self::name( $nonce ),
			(string) ( time() + $ttl ),
			self::noAutoload()
		);
		if ( 1 !== $wpdb->query( $sql ) ) {
			return false;
		}
		self::deleteExpired();
		return true;
	}

	private static function name( string $nonce ): string {
		return self::PREFIX . hash( 'sha256', $nonce );
	}

	/** WordPress 6.6 changed the autoload values from yes/no to on/off. */
	private static function noAutoload(): string {
		return function_exists( 'wp_determine_option_autoload_value' ) ? 'off' : 'no';
	}

	private static function deleteExpired(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d",
				$wpdb->esc_like( self::PREFIX ) . '%',
				time(),
				self::CLEANUP_BATCH
			)
		);
	}
}
