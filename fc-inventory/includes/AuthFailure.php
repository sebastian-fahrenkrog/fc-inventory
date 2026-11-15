<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * A rejected request. The message is a fixed reason for the error log and
 * never contains request data.
 */
final class AuthFailure extends \RuntimeException {
}
