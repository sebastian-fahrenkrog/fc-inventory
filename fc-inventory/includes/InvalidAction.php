<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * A correctly signed request whose body is not a valid order. The message is
 * returned to the (authenticated) caller.
 */
final class InvalidAction extends \RuntimeException {
}
