<?php
/**
 * End-to-end checks against a running WordPress with the plugin active
 * (driven by tests/e2e/run.sh). Acts as the puller: signs requests with the
 * test key and compares rejected responses with WordPress' own 404.
 *
 * Usage: php tests/e2e/e2e.php <base-url> <key-id> <secret-key-file>
 */

const PATH          = '/wp-json/fc-inventory/v1/inventory';
const FALLBACK_PATH = '/?rest_route=/fc-inventory/v1/inventory';

$failures = 0;

function check( bool $ok, string $what ): void {
	global $failures;
	fwrite( STDOUT, ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n" );
	if ( ! $ok ) {
		++$failures;
	}
}

function nonce(): string {
	return rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
}

/** Signed headers; $host and $ts can be overridden to build bad requests. */
function signed_headers( array $cfg, ?string $host = null, ?int $ts = null, ?string $nonce = null ): array {
	$ts      = (string) ( $ts ?? time() );
	$nonce   = $nonce ?? nonce();
	$message = implode( "\n", array( 'fc-inventory-v1', 'GET', $host ?? $cfg['host'], PATH, $ts, $nonce ) );
	return array(
		'X-FC-Key-Id: ' . $cfg['key_id'],
		'X-FC-Timestamp: ' . $ts,
		'X-FC-Nonce: ' . $nonce,
		'X-FC-Signature: ' . base64_encode( sodium_crypto_sign_detached( $message, $cfg['secret'] ) ),
	);
}

/** @return array{status: string, headers: list<string>, body: string} */
function request( array $cfg, string $path, array $headers = array() ): array {
	$ctx  = stream_context_create(
		array(
			'http' => array(
				'method'          => 'GET',
				'header'          => $headers,
				'ignore_errors'   => true,
				'follow_location' => 0,
				'timeout'         => 30,
			),
		)
	);
	$body = (string) file_get_contents( $cfg['base'] . $path, false, $ctx );
	$head = $http_response_header ?? array();
	return array( 'status' => (string) array_shift( $head ), 'headers' => $head, 'body' => $body );
}

/** Headers without Date (differs per second), as compared between 404s. */
function comparable( array $response ): array {
	$headers = array_values( array_filter( $response['headers'], fn( $h ) => 0 !== stripos( $h, 'Date:' ) ) );
	return array( $response['status'], $headers, $response['body'] );
}

function header_value( array $response, string $name ): ?string {
	foreach ( $response['headers'] as $h ) {
		if ( 0 === stripos( $h, $name . ':' ) ) {
			return trim( substr( $h, strlen( $name ) + 1 ) );
		}
	}
	return null;
}

function check_success( array $cfg, string $path ): void {
	$r    = request( $cfg, $path, signed_headers( $cfg ) );
	$json = json_decode( $r['body'], true );
	check( str_contains( $r['status'], ' 200' ), "signed $path → 200 ({$r['status']})" );
	check( 'no-store' === header_value( $r, 'Cache-Control' ), "signed $path → Cache-Control: no-store" );
	check( is_array( $json ) && 1 === ( $json['schema'] ?? null ), "signed $path → schema 1" );
	$slugs = is_array( $json ) ? array_column( $json['plugins'] ?? array(), 'slug' ) : array();
	check( in_array( 'fc-inventory', $slugs, true ), "signed $path → plugin reports itself" );
	check( '' !== ( $json['core'] ?? '' ) && '' !== ( $json['env']['db'] ?? '' ), "signed $path → core and db version present" );
}

/** A rejected request must look exactly like the 404 for an unknown route. */
function check_identical_404( array $cfg, string $what, string $path, array $headers, string $unknown_path ): void {
	$ours    = request( $cfg, $path, $headers );
	$unknown = request( $cfg, $unknown_path );
	check( str_contains( $ours['status'], ' 404' ), "$what → 404" );
	check( comparable( $ours ) === comparable( $unknown ), "$what → byte-identical to $unknown_path" );
	if ( comparable( $ours ) !== comparable( $unknown ) ) {
		fwrite( STDOUT, "  ours:    " . json_encode( comparable( $ours ) ) . "\n  unknown: " . json_encode( comparable( $unknown ) ) . "\n" );
	}
}

function check_rejections( array $cfg ): void {
	$unknown = '/wp-json/fc-inventory/v1/does-not-exist';
	check_identical_404( $cfg, 'unsigned', PATH, array(), $unknown );
	check_identical_404( $cfg, 'unsigned fallback form', FALLBACK_PATH, array(), '/?rest_route=/fc-inventory/v1/does-not-exist' );
	check_identical_404( $cfg, 'unsigned vs. foreign namespace', PATH, array(), '/wp-json/no-such-namespace/v1/x' );
	$replay = signed_headers( $cfg );
	request( $cfg, PATH, $replay );
	check_identical_404( $cfg, 'replayed nonce', PATH, $replay, $unknown );
	check_identical_404( $cfg, 'wrong host', PATH, signed_headers( $cfg, 'other.example.com' ), $unknown );
	check_identical_404( $cfg, 'expired timestamp', PATH, signed_headers( $cfg, null, time() - 301 ), $unknown );
}

function check_throttle( array $cfg ): void {
	for ( $i = 0; $i < 10; $i++ ) {
		request( $cfg, PATH, array( 'X-FC-Key-Id: ' . $cfg['key_id'] ) );
	}
	check_identical_404( $cfg, 'valid request while throttled', PATH, signed_headers( $cfg ), '/wp-json/fc-inventory/v1/does-not-exist' );
}

function config( array $argv ): array {
	if ( count( $argv ) !== 4 ) {
		fwrite( STDERR, "usage: php e2e.php <base-url> <key-id> <secret-key-file>\n" );
		exit( 2 );
	}
	$secret = base64_decode( trim( (string) file_get_contents( $argv[3] ) ), true );
	return array(
		'base'   => rtrim( $argv[1], '/' ),
		'host'   => strtolower( (string) parse_url( $argv[1], PHP_URL_HOST ) ) . ( parse_url( $argv[1], PHP_URL_PORT ) ? ':' . parse_url( $argv[1], PHP_URL_PORT ) : '' ),
		'key_id' => $argv[2],
		'secret' => $secret,
	);
}

$cfg = config( $argv );
check_success( $cfg, PATH );
check_success( $cfg, FALLBACK_PATH );
check_rejections( $cfg );
check_throttle( $cfg );
fwrite( STDOUT, 0 === $failures ? "e2e: all checks passed\n" : "e2e: $failures check(s) failed\n" );
exit( 0 === $failures ? 0 : 1 );
