<?php
/**
 * Removes everything the plugin stored: puller keys, last pull, nonce rows,
 * the log gate transient and the cached update manifest. On multisite for
 * every site of the network.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Deletes the plugin's rows from the options table of the current site. */
function fc_inventory_uninstall_site(): void {
	global $wpdb;
	delete_option( 'fc_inventory_pubkeys' );
	delete_option( 'fc_inventory_last_pull' );
	delete_transient( 'fc_inventory_log_gate' );
	$patterns = array( 'fc_inventory_', '_transient_fc_inventory_', '_transient_timeout_fc_inventory_' );
	foreach ( $patterns as $prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}
	wp_cache_delete( 'alloptions', 'options' );
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
