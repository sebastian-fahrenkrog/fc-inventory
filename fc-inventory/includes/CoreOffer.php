<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * Picks the core version to install from the versions WordPress offers.
 * Pure, so the branch rule is unit-tested without WordPress.
 */
final class CoreOffer {
	/** The x.y branch of a version: "6.8.3" and "6.8" are both "6.8". */
	public static function branch( string $version ): string {
		$parts = explode( '.', $version );
		return $parts[0] . '.' . ( $parts[1] ?? '0' );
	}

	/**
	 * @param list<string> $offered versions offered by the update API
	 * @return array{version: ?string, newer_branch: ?string} the version to
	 *         install (null: nothing allowed) and the newest version of a
	 *         newer branch that was skipped because $allow_major is false
	 */
	public static function pick( string $current, array $offered, bool $allow_major ): array {
		$newer = array_values( array_filter( $offered, static fn( string $v ): bool => version_compare( $v, $current, '>' ) ) );
		usort( $newer, 'version_compare' );
		$same = array_values( array_filter( $newer, static fn( string $v ): bool => self::branch( $v ) === self::branch( $current ) ) );
		if ( $allow_major ) {
			return array( 'version' => array() === $newer ? null : end( $newer ), 'newer_branch' => null );
		}
		$skipped = count( $newer ) > count( $same ) ? end( $newer ) : null;
		return array( 'version' => array() === $same ? null : end( $same ), 'newer_branch' => $skipped );
	}
}
