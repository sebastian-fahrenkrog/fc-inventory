<?php
/**
 * Removes everything the plugin stored: puller keys, last pull and last
 * action, nonce rows, the log gate transient, the cached update manifest and
 * the default backup directory with its archives. On multisite for
 * every site of the network.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Deletes the plugin's rows from the options table of the current site. */
function fc_inventory_uninstall_site(): void {
	global $wpdb;
	fc_inventory_remove_backups( get_option( 'fc_inventory_backup_token' ) );
	delete_option( 'fc_inventory_pubkeys' );
	delete_option( 'fc_inventory_last_pull' );
	delete_transient( 'fc_inventory_log_gate' );
	$patterns = array( 'fc_inventory_', '_transient_fc_inventory_', '_transient_timeout_fc_inventory_' );
	foreach ( $patterns as $prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}
	wp_cache_delete( 'alloptions', 'options' );
}

/**
 * Deletes the default backup directory. A directory set through the constant
 * FC_INVENTORY_BACKUP_DIR belongs to the site owner and stays.
 *
 * @param mixed $token
 */
function fc_inventory_remove_backups( $token ): void {
	if ( ! is_string( $token ) || 1 !== preg_match( '/^[0-9a-f]{24}$/D', $token ) ) {
		return;
	}
	$dir = WP_CONTENT_DIR . '/fc-inventory-backups-' . $token;
	foreach ( array_merge( (array) glob( $dir . '/*.zip' ), array( $dir . '/.htaccess', $dir . '/index.php' ) ) as $file ) {
		wp_delete_file( $file );
	}
	if ( is_dir( $dir ) ) {
		rmdir( $dir );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $fc_inventory_site_id ) {
		switch_to_blog( (int) $fc_inventory_site_id );
		fc_inventory_uninstall_site();
		restore_current_blog();
	}
} else {
	fc_inventory_uninstall_site();
}

delete_site_transient( 'fc_inventory_manifest' );
