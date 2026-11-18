<?php
/**
 * Writes tests/vectors/action-v1.json, the shared test vector of contract
 * action-v1 (signed orders). Deterministic like signature-v1.json: key pair
 * from seed = 32 bytes of 0x01, fixed timestamp and nonces. The PHP tests and
 * the Python client tests (tools/fc_action) use the same file.
 *
 * Usage: php scripts/gen-action-vector.php [output-path]
 */

define( 'FC_INVENTORY_TESTING', true );
require dirname( __DIR__ ) . '/fc-inventory/includes/load.php';

use FcInventory\Signature;

define( 'SEED', str_repeat( "\x01", 32 ) );
const HOST      = 'www.example.com';
const TIMESTAMP = '1794729600';

/** @return array<string,string> */
function build_case( string $name, string $nonce_raw, string $body ): array {
	$nonce   = rtrim( strtr( base64_encode( $nonce_raw ), '+/', '-_' ), '=' );
	$message = Signature::actionMessage( HOST, TIMESTAMP, $nonce, $body );
	$secret  = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( SEED ) );
	return array(
		'name'        => $name,
		'nonce'       => $nonce,
		'body'        => $body,
		'body_sha256' => hash( 'sha256', $body ),
		'message'     => $message,
		'signature'   => base64_encode( sodium_crypto_sign_detached( $message, $secret ) ),
	);
}

$vector = array(
	'description' => 'fc-inventory contract action-v1 vector. Signed message: prefix, method, host, path, timestamp, nonce and the lowercase hex SHA-256 of the raw request body, joined by "\n". Ed25519 signatures are deterministic, so every "signature" must match byte for byte.',
	'seed_hex'    => bin2hex( SEED ),
	'public_key'  => base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( SEED ) ) ),
	'key_id'      => 'ops-2026-11',
	'prefix'      => Signature::ACTION_PREFIX,
	'method'      => Signature::ACTION_METHOD,
	'host'        => HOST,
	'path'        => Signature::ACTION_PATH,
	'timestamp'   => (int) TIMESTAMP,
	'cases'       => array(
		build_case( 'update-plugin', implode( '', array_map( 'chr', range( 0, 15 ) ) ), '{"schema":1,"action":"update_plugin","slug":"akismet"}' ),
		build_case( 'update-core-major', str_repeat( "\x01", 16 ), '{"schema":1,"action":"update_core","allow_major":true}' ),
	),
);

$out  = $argv[1] ?? dirname( __DIR__ ) . '/tests/vectors/action-v1.json';
$json = json_encode( $vector, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( false === file_put_contents( $out, $json ) ) {
	fwrite( STDERR, "cannot write $out\n" );
	exit( 1 );
}
fwrite( STDERR, "wrote $out\n" );
