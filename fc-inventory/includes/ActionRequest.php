<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * One validated order of contract action-v1. Pure: parsing and validation
 * run without WordPress.
 *
 * Body: {"schema":1,"action":"update_plugin","slug":"akismet"}
 * Actions: update_plugin, update_theme, deactivate_plugin (need "slug"),
 * update_core (no slug; "allow_major": true permits a new x.y branch).
 */
final class ActionRequest {
	public const SCHEMA = 1;

	public const UPDATE_PLUGIN     = 'update_plugin';
	public const UPDATE_THEME      = 'update_theme';
	public const UPDATE_CORE       = 'update_core';
	public const DEACTIVATE_PLUGIN = 'deactivate_plugin';

	private const WITH_SLUG = array( self::UPDATE_PLUGIN, self::UPDATE_THEME, self::DEACTIVATE_PLUGIN );
	private const ALLOWED   = array( 'schema', 'action', 'slug', 'allow_major' );

	/** Directory or file names as WordPress allows them; no path separators, no leading dot. */
	private const SLUG_RE = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,99}$/D';

	private function __construct(
		public readonly string $action,
		public readonly string $slug,
		public readonly bool $allow_major
	) {
	}

	/**
	 * @throws InvalidAction if the body is not exactly one valid order
	 */
	public static function fromJson( string $body ): self {
		$data = json_decode( $body, true, 4 );
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw new InvalidAction( 'body is not a JSON object' );
		}
		$unknown = array_diff( array_keys( $data ), self::ALLOWED );
		if ( array() !== $unknown ) {
			throw new InvalidAction( 'unknown field: ' . implode( ', ', array_map( 'strval', $unknown ) ) );
		}
		if ( self::SCHEMA !== ( $data['schema'] ?? null ) ) {
			throw new InvalidAction( 'unsupported schema' );
		}
		$action = self::action( $data['action'] ?? null );
		return new self( $action, self::slug( $action, $data['slug'] ?? null ), self::allowMajor( $action, $data ) );
	}

	/** @param mixed $action */
	private static function action( $action ): string {
		$known = array( self::UPDATE_PLUGIN, self::UPDATE_THEME, self::UPDATE_CORE, self::DEACTIVATE_PLUGIN );
		if ( ! is_string( $action ) || ! in_array( $action, $known, true ) ) {
			throw new InvalidAction( 'unknown action' );
		}
		return $action;
	}

	/** @param mixed $slug */
	private static function slug( string $action, $slug ): string {
		if ( ! in_array( $action, self::WITH_SLUG, true ) ) {
			if ( null !== $slug ) {
				throw new InvalidAction( 'slug is not allowed for this action' );
			}
			return '';
		}
		if ( ! is_string( $slug ) || 1 !== preg_match( self::SLUG_RE, $slug ) || str_contains( $slug, '..' ) ) {
			throw new InvalidAction( 'missing or malformed slug' );
		}
		return $slug;
	}

	/** @param array<mixed,mixed> $data */
	private static function allowMajor( string $action, array $data ): bool {
		if ( ! array_key_exists( 'allow_major', $data ) ) {
			return false;
		}
		if ( self::UPDATE_CORE !== $action || ! is_bool( $data['allow_major'] ) ) {
			throw new InvalidAction( 'allow_major must be a boolean and is only valid for update_core' );
		}
		return $data['allow_major'];
	}
}
