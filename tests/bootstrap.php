<?php
/**
 * Loads the pure plugin classes without WordPress.
 */

define( 'FC_INVENTORY_TESTING', true );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/fc-inventory/includes/load.php';
require __DIR__ . '/unit/ArrayNonceStore.php';
