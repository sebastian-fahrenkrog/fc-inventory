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
const ACTION_PATH   = '/wp-json/fc-inventory/v1/action';
const ACTION_BODY   = '{"schema":1,"action":"deactivate_plugin","slug":"akismet"}';

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
function request( array $cfg, string $path, array $headers = array(), string $method = 'GET', string $content = '' ): array {
	$ctx  = stream_context_create(
		array(
			'http' => array(
				'method'          => $method,
				'header'          => $headers,
				'content'         => $content,
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

/**
 * A rejected request must look exactly like the same request to an unknown
 * route: 404 rest_no_route, or for OPTIONS the empty 200 WordPress sends there.
 */
function check_identical_404( array $cfg, string $what, string $path, array $headers, string $unknown_path, string $method = 'GET', string $content = '' ): void {
	$ours     = request( $cfg, $path, $headers, $method, $content );
	$unknown  = request( $cfg, $unknown_path, array(), $method, $content );
	$expected = 'OPTIONS' === $method ? ' 200' : ' 404';
	check( str_contains( $ours['status'], $expected ), "$what →$expected" );
	check( comparable( $ours ) === comparable( $unknown ), "$what → byte-identical to $method $unknown_path" );
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

/** The namespace must not show up anywhere a visitor can look without a key. */
function check_hidden( array $cfg ): void {
	$index = request( $cfg, '/wp-json/' );
	check( str_contains( $index['status'], ' 200' ) && ! str_contains( $index['body'], 'fc-inventory' ), 'GET /wp-json/ does not mention fc-inventory' );
	$fallback_index = request( $cfg, '/?rest_route=/' );
	check( ! str_contains( $fallback_index['body'], 'fc-inventory' ), 'GET /?rest_route=/ does not mention fc-inventory' );
	check_identical_404( $cfg, 'namespace index', '/wp-json/fc-inventory/v1', array(), '/wp-json/no-such-namespace/v1' );
	check_identical_404( $cfg, 'namespace index, fallback form', '/?rest_route=/fc-inventory/v1', array(), '/?rest_route=/no-such-namespace/v1' );
	check_identical_404( $cfg, 'OPTIONS on the route', PATH, array(), '/wp-json/no-such-namespace/v1/x', 'OPTIONS' );
	check_identical_404( $cfg, 'signed OPTIONS on the route', PATH, signed_headers( $cfg ), '/wp-json/no-such-namespace/v1/x', 'OPTIONS' );
	check_identical_404( $cfg, 'POST on the route', PATH, array(), '/wp-json/no-such-namespace/v1/x', 'POST' );
}

/**
 * The action route answers only to the separate action keys: unsigned
 * orders and orders signed with the puller (read) key look like an unknown route.
 */
function check_action_rejections( array $cfg ): void {
	$unknown = '/wp-json/no-such-namespace/v1/x';
	check_identical_404( $cfg, 'unsigned order', ACTION_PATH, array(), $unknown, 'POST', ACTION_BODY );
	$ts      = (string) time();
	$nonce   = nonce();
	$message = implode( "\n", array( 'fc-inventory-action-v1', 'POST', $cfg['host'], ACTION_PATH, $ts, $nonce, hash( 'sha256', ACTION_BODY ) ) );
	$headers = array(
		'X-FC-Key-Id: ' . $cfg['key_id'],
		'X-FC-Timestamp: ' . $ts,
		'X-FC-Nonce: ' . $nonce,
		'X-FC-Signature: ' . base64_encode( sodium_crypto_sign_detached( $message, $cfg['secret'] ) ),
	);
	check_identical_404( $cfg, 'order signed with the puller key', ACTION_PATH, $headers, $unknown, 'POST', ACTION_BODY );
	check_identical_404( $cfg, 'GET on the action route', ACTION_PATH, array(), $unknown );
}

/** Unsigned noise must not lock the puller out (no failure throttle). */
function check_no_lockout( array $cfg ): void {
	for ( $i = 0; $i < 15; $i++ ) {
		request( $cfg, PATH );
	}
	$r = request( $cfg, PATH, signed_headers( $cfg ) );
	check( str_contains( $r['status'], ' 200' ), "signed request after 15 unsigned ones → 200 ({$r['status']})" );
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
check_hidden( $cfg );
check_action_rejections( $cfg );
check_no_lockout( $cfg );
fwrite( STDOUT, 0 === $failures ? "e2e: all checks passed\n" : "e2e: $failures check(s) failed\n" );
exit( 0 === $failures ? 0 : 1 );
