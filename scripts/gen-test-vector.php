<?php
/**
 * Writes tests/vectors/signature-v1.json, the shared test vector of contract
 * v1. Deterministic: Ed25519 key pair from seed = 32 bytes of 0x01, fixed
 * nonce 0x00..0x0f, fixed timestamp. The Go puller tests use the same file.
 *
 * Usage: php scripts/gen-test-vector.php [output-path]
 */

define( 'FC_INVENTORY_TESTING', true );
require dirname( __DIR__ ) . '/fc-inventory/includes/load.php';

use FcInventory\Signature;

/** @return array<string,mixed> */
function build_vector(): array {
	$seed      = str_repeat( "\x01", 32 );
	$keypair   = sodium_crypto_sign_seed_keypair( $seed );
	$nonce_raw = implode( '', array_map( 'chr', range( 0, 15 ) ) );
	$nonce     = rtrim( strtr( base64_encode( $nonce_raw ), '+/', '-_' ), '=' );
	$host      = 'www.example.com';
	$timestamp = '1794729600';
	$message   = Signature::message( $host, Signature::CANONICAL_PATH, $timestamp, $nonce );
	$signature = sodium_crypto_sign_detached( $message, sodium_crypto_sign_secretkey( $keypair ) );

	return array(
		'description'   => 'fc-inventory contract v1 signature vector. Key pair: sodium_crypto_sign_seed_keypair(seed) in PHP, ed25519.NewKeyFromSeed(seed) in Go. Ed25519 signatures are deterministic, so "signature" must match byte for byte.',
		'seed_hex'      => bin2hex( $seed ),
		'public_key'    => base64_encode( sodium_crypto_sign_publickey( $keypair ) ),
		'key_id'        => '2026-11',
		'method'        => Signature::METHOD,
		'host'          => $host,
		'path'          => Signature::CANONICAL_PATH,
		'timestamp'     => (int) $timestamp,
		'nonce_hex'     => bin2hex( $nonce_raw ),
		'nonce'         => $nonce,
		'message'       => $message,
		'message_hex'   => bin2hex( $message ),
		'signature'     => base64_encode( $signature ),
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
