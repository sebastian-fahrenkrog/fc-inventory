<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * Tools → FC Inventory: status and the puller key list.
 */
final class Admin {
	private const SLUG   = 'fc-inventory';
	private const NONCE  = 'fc_inventory_save_keys';
	private const FIELD  = 'fc_inventory_keys';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FC_INVENTORY_FILE ), array( self::class, 'actionLinks' ) );
	}

	/**
	 * On multisite every site admin has manage_options, but the keys decide
	 * who can read the inventory, so only network admins may change them.
	 */
	public static function capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	public static function menu(): void {
		add_management_page( 'FC Inventory', 'FC Inventory', self::capability(), self::SLUG, array( self::class, 'render' ) );
	}

	/** @param array<string,string> $links */
	public static function actionLinks( array $links ): array {
		$url = admin_url( 'tools.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'fc-inventory' ) . '</a>' );
		return $links;
	}

	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'fc-inventory' ) );
		}
		$notice = self::handlePost();
		echo '<div class="wrap"><h1>FC Inventory</h1>';
		self::renderNotice( $notice );
		self::renderStatus();
		self::renderForm();
		echo '</div>';
	}

	/** @return array{type: string, lines: list<string>}|null */
	private static function handlePost(): ?array {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::FIELD ] ) ) {
			return null;
		}
		check_admin_referer( self::NONCE );
		if ( Keys::fromConstant() ) {
			return array( 'type' => 'error', 'lines' => array( 'FC_INVENTORY_PUBKEYS is set in wp-config.php; the option is not used.' ) );
		}
		list( $map, $errors ) = Keys::parseText( (string) wp_unslash( $_POST[ self::FIELD ] ) );
		if ( array() !== $errors ) {
			return array( 'type' => 'error', 'lines' => array_merge( array( 'Nothing saved.' ), $errors ) );
		}
		update_option( Keys::OPTION, $map, false );
		return array( 'type' => 'success', 'lines' => array( sprintf( 'Saved %d key(s).', count( $map ) ) ) );
	}

	private static function renderNotice( ?array $notice ): void {
		if ( null === $notice ) {
			return;
		}
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $notice['type'] ), implode( '<br>', array_map( 'esc_html', $notice['lines'] ) ) );
	}

	private static function renderStatus(): void {
		$rows = array(
			'ext-sodium'       => Endpoint::sodiumAvailable() ? 'available' : 'MISSING – the endpoint is disabled',
			'Key source'       => Keys::fromConstant() ? 'constant FC_INVENTORY_PUBKEYS (wp-config.php)' : 'option (form below)',
			'Configured keys'  => self::keyIdList(),
			'Endpoint'         => Endpoint::url(),
			'Fallback URL'     => Endpoint::fallbackUrl(),
			'Last pull'        => self::lastPull(),
			'Plugin version'   => FC_INVENTORY_VERSION,
		);
		echo '<h2>Status</h2><table class="widefat striped" style="max-width:60em"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row" style="width:12em">%s</th><td><code>%s</code></td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</tbody></table>';
	}

	private static function keyIdList(): string {
		$ids = array_keys( Keys::configured() );
		return array() === $ids ? 'none – every request gets 404' : implode( ', ', $ids );
	}

	private static function lastPull(): string {
		$last = get_option( Endpoint::LAST_PULL );
		if ( ! is_array( $last ) || empty( $last['time'] ) ) {
			return 'never';
		}
		return sprintf( '%s UTC (key %s)', gmdate( 'Y-m-d H:i:s', (int) $last['time'] ), (string) ( $last['key_id'] ?? '' ) );
	}

	private static function renderForm(): void {
		$readonly = Keys::fromConstant();
		echo '<h2>Puller public keys</h2>';
		if ( $readonly ) {
			echo '<div class="notice notice-info inline"><p>The constant <code>FC_INVENTORY_PUBKEYS</code> is set in <code>wp-config.php</code>. It wins over this form, which is read-only.</p></div>';
		}
		echo '<p>One key per line: <code>&lt;key-id&gt; &lt;base64 Ed25519 public key&gt;</code>. Key ids: A–Z, a–z, 0–9, <code>.</code> <code>_</code> <code>-</code>, up to 32 characters. Several keys allow rotation without downtime.</p>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		printf(
			'<textarea name="%s" rows="6" cols="80" class="large-text code" %s>%s</textarea>',
			esc_attr( self::FIELD ),
			$readonly ? 'readonly' : '',
			esc_textarea( self::keyText() )
		);
		if ( ! $readonly ) {
			submit_button( 'Save keys' );
		}
		echo '</form>';
	}

	private static function keyText(): string {
		$map   = Keys::fromConstant() ? constant( Keys::CONSTANT ) : get_option( Keys::OPTION, array() );
		$lines = array();
		foreach ( is_array( $map ) ? $map : array() as $id => $b64 ) {
			$lines[] = $id . ' ' . ( is_string( $b64 ) ? $b64 : '' );
		}
		return implode( "\n", $lines );
	}
}
