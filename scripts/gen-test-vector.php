<?php
/**
 * Writes tests/vectors/signature-v1.json, the shared test vector of contract
 * v1. Deterministic: Ed25519 key pair from seed = 32 bytes of 0x01, fixed
 * timestamp, two cases with fixed nonces (0x00..0x0f and 16 x 0x01). The Go puller tests use the same file.
 *
 * Usage: php scripts/gen-test-vector.php [output-path]
 */

define( 'FC_INVENTORY_TESTING', true );
require dirname( __DIR__ ) . '/fc-inventory/includes/load.php';

use FcInventory\Signature;

define( 'SEED', str_repeat( "\x01", 32 ) );
const HOST      = 'www.example.com';
const TIMESTAMP = '1794729600';

/**
 * One signed request for the given raw nonce bytes.
 *
 * @return array<string,string>
 */
function build_case( string $name, string $nonce_raw ): array {
	$nonce   = rtrim( strtr( base64_encode( $nonce_raw ), '+/', '-_' ), '=' );
	$message = Signature::message( HOST, Signature::CANONICAL_PATH, TIMESTAMP, $nonce );
	$secret  = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( SEED ) );
	return array(
		'name'        => $name,
		'nonce_hex'   => bin2hex( $nonce_raw ),
		'nonce'       => $nonce,
		'message'     => $message,
		'message_hex' => bin2hex( $message ),
		'signature'   => base64_encode( sodium_crypto_sign_detached( $message, $secret ) ),
	);
}

/**
 * Shared fields, the first case repeated at the top level (the format of
 * the first version of this file) and all cases under "cases".
 *
 * @return array<string,mixed>
 */
function build_vector(): array {
	$cases = array(
		build_case( 'nonce-00-0f', implode( '', array_map( 'chr', range( 0, 15 ) ) ) ),
		build_case( 'nonce-01', str_repeat( "\x01", 16 ) ),
	);
	$first = $cases[0];
	return array(
		'description'   => 'fc-inventory contract v1 signature vector. Key pair: sodium_crypto_sign_seed_keypair(seed) in PHP, ed25519.NewKeyFromSeed(seed) in Go. Ed25519 signatures are deterministic, so every "signature" must match byte for byte. The top-level nonce/message/signature repeat cases[0].',
		'seed_hex'      => bin2hex( SEED ),
		'public_key'    => base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( SEED ) ) ),
		'key_id'        => '2026-11',
		'method'        => Signature::METHOD,
		'host'          => HOST,
		'path'          => Signature::CANONICAL_PATH,
		'timestamp'     => (int) TIMESTAMP,
		'nonce_hex'     => $first['nonce_hex'],
		'nonce'         => $first['nonce'],
		'message'       => $first['message'],
		'message_hex'   => $first['message_hex'],
		'signature'     => $first['signature'],
		'cases'         => $cases,
		'host_examples' => host_examples(),
	);
}

/** How the plugin derives <host> from HTTP_HOST and the request scheme. */
function host_examples(): array {
	$cases = array(
		array( 'WWW.Example.COM', true ),
		array( 'www.example.com:443', true ),
		array( 'www.example.com:80', false ),
		array( 'www.example.com:80', true ),
		array( 'www.example.com:8443', true ),
		array( 'xn--bcher-kva.example', true ),
	);
	$out = array();
	foreach ( $cases as list( $http_host, $https ) ) {
		$out[] = array(
			'http_host' => $http_host,
			'https'     => $https,
			'host'      => Signature::normalizeHost( $http_host, $https ),
		);
	}
	return $out;
}

$out  = $argv[1] ?? dirname( __DIR__ ) . '/tests/vectors/signature-v1.json';
$json = json_encode( build_vector(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
if ( false === file_put_contents( $out, $json ) ) {
	fwrite( STDERR, "cannot write $out\n" );
	exit( 1 );
}
fwrite( STDERR, "wrote $out\n" );
