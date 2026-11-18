<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * Carries out one verified order with WordPress' own upgraders.
 *
 * The request never names a download: plugins, themes and core are only
 * moved to the version WordPress itself offers as an update (including the
 * offers of commercial plugins that hook into the update check). Plugins and
 * themes are zipped first and restored if the upgrade fails or the site
 * answers 5xx afterwards. Core has no file backup; WordPress rolls a failed
 * core update back itself.
 */
final class ActionRunner {
	public const LAST_ACTION = 'fc_inventory_last_action';
	public const LOCK        = 'fc_inventory_action_lock';
	private const LOCK_TTL   = 900;

	/** @return array<string,mixed> the result sent back to the caller */
	public static function run( ActionRequest $request, string $key_id ): array {
		$result = array( 'schema' => ActionRequest::SCHEMA, 'action' => $request->action, 'slug' => $request->slug );
		if ( ! self::lock() ) {
			return $result + array( 'result' => 'failed', 'messages' => array( 'another action is running' ) );
		}
		try {
			self::prepare();
			$result += self::dispatch( $request );
		} catch ( \Throwable $e ) {
			$result += array( 'result' => 'failed', 'messages' => array( $e->getMessage() ) );
		} finally {
			delete_option( self::LOCK );
		}
		self::record( $result, $key_id );
		return $result;
	}

	/** Atomic: add_option fails if the row exists. A stale lock is taken over. */
	private static function lock(): bool {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		if ( time() - (int) get_option( self::LOCK ) < self::LOCK_TTL ) {
			return false;
		}
		update_option( self::LOCK, time(), false );
		return true;
	}

	private static function prepare(): void {
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore -- hosts may forbid it; the default limit then applies.
		}
		foreach ( array( 'file', 'plugin', 'theme', 'misc', 'update', 'class-wp-upgrader' ) as $name ) {
			require_once ABSPATH . 'wp-admin/includes/' . $name . '.php';
		}
		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			throw new \RuntimeException( 'WordPress cannot write files directly (FS_METHOD is not "direct")' );
		}
	}

	/** @return array<string,mixed> */
	private static function dispatch( ActionRequest $request ): array {
		switch ( $request->action ) {
			case ActionRequest::UPDATE_PLUGIN:
				return self::updatePlugin( $request->slug );
			case ActionRequest::UPDATE_THEME:
				return self::updateTheme( $request->slug );
			case ActionRequest::DEACTIVATE_PLUGIN:
				return self::deactivatePlugin( $request->slug );
			default:
				return self::updateCore( $request->allow_major );
		}
	}

	/** Plugin file ("dir/main.php" or "hello.php") for an inventory slug. */
	private static function pluginFile( string $slug ): string {
		foreach ( array_keys( get_plugins() ) as $file ) {
			$own = str_contains( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
			if ( $own === $slug ) {
				return $file;
			}
		}
		throw new \RuntimeException( 'plugin is not installed' );
	}

	private static function pluginVersion( string $file ): string {
		wp_clean_plugins_cache( false );
		return (string) ( get_plugins()[ $file ]['Version'] ?? '' );
	}

	/** @return array<string,mixed> */
	private static function updatePlugin( string $slug ): array {
		$file = self::pluginFile( $slug );
		$from = self::pluginVersion( $file );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$offers = get_site_transient( 'update_plugins' );
		$offer  = is_object( $offers ) ? ( $offers->response[ $file ] ?? null ) : null;
		if ( ! is_object( $offer ) || empty( $offer->package ) ) {
			return array( 'result' => 'no_update', 'from' => $from, 'messages' => array( is_object( $offer ) ? 'an update is offered without a download (licence?)' : 'no update offered' ) );
		}
		$path   = str_contains( $file, '/' ) ? WP_PLUGIN_DIR . '/' . dirname( $file ) : WP_PLUGIN_DIR . '/' . $file;
		$active = array( 'site' => is_plugin_active( $file ), 'network' => is_plugin_active_for_network( $file ) );
		$apply  = static function () use ( $file ) {
			$skin = new \Automatic_Upgrader_Skin();
			return array( ( new \Plugin_Upgrader( $skin ) )->upgrade( $file ), $skin->get_upgrade_messages() );
		};
		$after  = static function () use ( $file, $active ): void {
			wp_clean_plugins_cache( true );
			self::reactivate( $file, $active );
		};
		$out = self::guarded( 'plugin', $slug, $from, $path, $apply, $after );
		return $out + array( 'from' => $from, 'to' => self::pluginVersion( $file ), 'offered' => (string) $offer->new_version );
	}

	/** An upgrade outside wp-admin can leave the plugin inactive; put it back as it was. */
	private static function reactivate( string $file, array $active ): void {
		if ( $active['network'] && ! is_plugin_active_for_network( $file ) ) {
			activate_plugin( $file, '', true, true );
		} elseif ( $active['site'] && ! is_plugin_active( $file ) ) {
			activate_plugin( $file, '', false, true );
		}
	}

	/** @return array<string,mixed> */
	private static function updateTheme( string $slug ): array {
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			throw new \RuntimeException( 'theme is not installed' );
		}
		$from = (string) $theme->get( 'Version' );
		delete_site_transient( 'update_themes' );
		wp_update_themes();
		$offers = get_site_transient( 'update_themes' );
		$offer  = is_object( $offers ) ? ( $offers->response[ $slug ] ?? null ) : null;
		if ( ! is_array( $offer ) || empty( $offer['package'] ) ) {
			return array( 'result' => 'no_update', 'from' => $from, 'messages' => array( is_array( $offer ) ? 'an update is offered without a download (licence?)' : 'no update offered' ) );
		}
		$apply = static function () use ( $slug ) {
			$skin = new \Automatic_Upgrader_Skin();
			return array( ( new \Theme_Upgrader( $skin ) )->upgrade( $slug ), $skin->get_upgrade_messages() );
		};
		$after = static function (): void {
			wp_clean_themes_cache( true );
		};
		$out = self::guarded( 'theme', $slug, $from, $theme->get_stylesheet_directory(), $apply, $after );
		return $out + array( 'from' => $from, 'to' => (string) wp_get_theme( $slug )->get( 'Version' ), 'offered' => (string) $offer['new_version'] );
	}

	/**
	 * Backup, apply, probe, restore on failure.
	 *
	 * @param callable():array{0:mixed,1:list<string>} $apply runs the upgrader
	 * @param callable():void                          $after refreshes caches and state
	 * @return array<string,mixed>
	 */
	private static function guarded( string $kind, string $slug, string $version, string $path, callable $apply, callable $after ): array {
		$before = Health::probe();
		$zip    = Backup::create( $kind, $slug, $version, $path );
		list( $status, $messages ) = $apply();
		$after();
		$ok     = true === $status;
		$health = array( 'before' => $before, 'after' => Health::probe() );
		if ( is_wp_error( $status ) ) {
			$messages[] = $status->get_error_message();
		}
		$out = array( 'backup' => basename( $zip ), 'health' => $health, 'messages' => self::plain( $messages ) );
		if ( $ok && ! Health::regressed( $before, $health['after'] ) ) {
			if ( ! Health::usable( $before ) ) {
				$out['messages'][] = 'loopback probes do not work on this host; the site was not checked after the change';
			}
			return $out + array( 'result' => 'updated' );
		}
		$restored = Backup::restore( $zip, $path );
		$after();
		$out['health']['restored'] = Health::probe();
		$out['messages'][]         = $ok ? 'site answered with a server error after the update' : 'upgrade failed';
		return $out + array( 'result' => $restored ? 'rolled_back' : 'failed' );
	}

	/** @return array<string,mixed> */
	private static function deactivatePlugin( string $slug ): array {
		$file = self::pluginFile( $slug );
		if ( plugin_basename( FC_INVENTORY_FILE ) === $file ) {
			throw new \RuntimeException( 'fc-inventory does not deactivate itself' );
		}
		$network = is_plugin_active_for_network( $file );
		if ( ! $network && ! is_plugin_active( $file ) ) {
			return array( 'result' => 'not_active' );
		}
		$before = Health::probe();
		deactivate_plugins( $file, false, $network );
		$health = array( 'before' => $before, 'after' => Health::probe() );
		if ( ! Health::regressed( $before, $health['after'] ) ) {
			return array( 'result' => 'deactivated', 'health' => $health );
		}
		activate_plugin( $file, '', $network, true );
		$health['restored'] = Health::probe();
		return array( 'result' => 'rolled_back', 'health' => $health, 'messages' => array( 'site answered with a server error without the plugin; it is active again' ) );
	}

	/** @return array<string,mixed> */
	private static function updateCore( bool $allow_major ): array {
		$from = (string) get_bloginfo( 'version' );
		wp_version_check( array(), true );
		$offers = get_site_transient( 'update_core' );
		$list   = is_object( $offers ) && is_array( $offers->updates ?? null ) ? $offers->updates : array();
		$byVer  = array();
		foreach ( $list as $offer ) {
			if ( is_object( $offer ) && in_array( $offer->response ?? '', array( 'upgrade', 'autoupdate' ), true ) && ! empty( $offer->version ) ) {
				$byVer[ (string) $offer->version ] = $offer;
			}
		}
		$pick = CoreOffer::pick( $from, array_map( 'strval', array_keys( $byVer ) ), $allow_major );
		if ( null === $pick['version'] ) {
			$newer = $pick['newer_branch'];
			return array( 'result' => null === $newer ? 'no_update' : 'major_only', 'from' => $from, 'offered' => (string) $newer );
		}
		$before = Health::probe();
		$skin   = new \Automatic_Upgrader_Skin();
		$status = ( new \Core_Upgrader( $skin ) )->upgrade( $byVer[ $pick['version'] ], array( 'allow_relaxed_file_ownership' => true ) );
		$msgs   = self::plain( $skin->get_upgrade_messages() );
		if ( is_wp_error( $status ) || ! $status ) {
			$msgs[] = is_wp_error( $status ) ? $status->get_error_message() : 'core upgrade failed';
			return array( 'result' => 'failed', 'from' => $from, 'offered' => $pick['version'], 'messages' => $msgs );
		}
		// A fresh request runs the database upgrade with the new code, as the automatic updater does.
		wp_remote_post( admin_url( 'upgrade.php?step=upgrade_db' ), array( 'timeout' => 60, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
		$health = array( 'before' => $before, 'after' => Health::probe() );
		if ( Health::regressed( $before, $health['after'] ) ) {
			$msgs[] = 'site answered with a server error after the core update; core has no automatic restore';
		}
		return array( 'result' => 'updated', 'from' => $from, 'to' => (string) $status, 'health' => $health, 'messages' => $msgs );
	}

	/**
	 * Upgrader messages as plain text (they come with markup and HTML entities).
	 *
	 * @param array<mixed> $messages
	 * @return list<string>
	 */
	private static function plain( array $messages ): array {
		$clean = static fn( $m ): string => html_entity_decode( wp_strip_all_tags( (string) $m ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return array_values( array_map( $clean, $messages ) );
	}

	/** @param array<string,mixed> $result */
	private static function record( array $result, string $key_id ): void {
		$entry = array(
			'time'   => time(),
			'key_id' => $key_id,
			'action' => $result['action'],
			'slug'   => $result['slug'],
			'result' => $result['result'] ?? 'failed',
			'from'   => $result['from'] ?? '',
			'to'     => $result['to'] ?? '',
		);
		update_option( self::LAST_ACTION, $entry, false );
		error_log( sprintf( 'fc-inventory: action %s %s by key %s: %s (%s -> %s)', $entry['action'], $entry['slug'], $key_id, $entry['result'], $entry['from'], $entry['to'] ) );
	}
}
