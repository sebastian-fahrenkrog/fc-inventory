<?php
/**
 * Builds the release assets into an output directory:
 *   fc-inventory.zip       the plugin, unpacking to fc-inventory/fc-inventory.php
 *   fc-inventory.json      the manifest the plugin's update channel reads
 *   fc-inventory.json.sig  Ed25519 detached signature of the manifest, base64
 *   release-notes.md       the CHANGELOG section of this version
 *
 * The signing key is the base64 Ed25519 secret key (64 bytes), read from
 * --key-file or the environment variable FC_INVENTORY_SIGNING_KEY. It must
 * belong to the release public key compiled into the plugin.
 *
 * Usage: php scripts/build-release.php --version=1.0.0 [--out=dist] [--key-file=PATH]
 */

define( 'FC_INVENTORY_TESTING', true );

$root = dirname( __DIR__ );
require $root . '/fc-inventory/includes/load.php';

use FcInventory\Manifest;

const PLUGIN_DIR = 'fc-inventory';

function fail( string $message ): void {
	fwrite( STDERR, "error: $message\n" );
	exit( 1 );
}

function info( string $message ): void {
	fwrite( STDERR, "$message\n" );
}

/** @return array{version: string, out: string, key_file: ?string} */
function parse_args(): array {
	$opts = getopt( '', array( 'version:', 'out:', 'key-file:', 'help' ) );
	if ( isset( $opts['help'] ) || empty( $opts['version'] ) ) {
		fwrite( STDERR, "Usage: php scripts/build-release.php --version=X.Y.Z [--out=dist] [--key-file=PATH]\n" );
		fwrite( STDERR, "Key: --key-file or env FC_INVENTORY_SIGNING_KEY (base64 Ed25519 secret key).\n" );
		exit( isset( $opts['help'] ) ? 0 : 1 );
	}
	return array(
		'version'  => ltrim( (string) $opts['version'], 'v' ),
		'out'      => (string) ( $opts['out'] ?? 'dist' ),
		'key_file' => isset( $opts['key-file'] ) ? (string) $opts['key-file'] : null,
	);
}

/** Reads one header ("Requires PHP: 8.1") from a file. */
function read_header( string $file, string $name ): string {
	$data = (string) file_get_contents( $file );
	if ( ! preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $data, $m ) ) {
		fail( "header '$name' missing in $file" );
	}
	return trim( $m[1] );
}

/** Header Version, FC_INVENTORY_VERSION and readme Stable tag must all equal the tag. */
function check_version( string $root, string $version ): void {
	$main   = "$root/" . PLUGIN_DIR . '/fc-inventory.php';
	$found  = array(
		'plugin header'        => read_header( $main, 'Version' ),
		'readme.txt Stable tag' => read_header( "$root/" . PLUGIN_DIR . '/readme.txt', 'Stable tag' ),
	);
	if ( preg_match( "/define\\(\\s*'FC_INVENTORY_VERSION',\\s*'([^']+)'/", (string) file_get_contents( $main ), $m ) ) {
		$found['FC_INVENTORY_VERSION'] = $m[1];
	}
	foreach ( $found as $where => $value ) {
		if ( $value !== $version ) {
			fail( "$where is '$value', expected '$version'" );
		}
	}
	if ( count( $found ) !== 3 ) {
		fail( 'FC_INVENTORY_VERSION not found in fc-inventory.php' );
	}
}

/** @return list<string> plugin files relative to the plugin directory, sorted */
function plugin_files( string $dir ): array {
	$files = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() ) {
			$files[] = substr( $file->getPathname(), strlen( $dir ) + 1 );
		}
	}
	sort( $files, SORT_STRING );
	return $files;
}

/** Fixed timestamps make the ZIP reproducible for the same sources. */
function build_zip( string $root, string $zip_path ): void {
	$dir = "$root/" . PLUGIN_DIR;
	@unlink( $zip_path );
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
		fail( "cannot create $zip_path" );
	}
	foreach ( plugin_files( $dir ) as $rel ) {
		$name = PLUGIN_DIR . '/' . $rel;
		$zip->addFile( "$dir/$rel", $name );
		$zip->setMtimeName( $name, 315532800 );
	}
	$zip->close();
}

function changelog_section( string $root, string $version ): string {
	$text = (string) @file_get_contents( "$root/CHANGELOG.md" );
	$re   = '/^## \[?' . preg_quote( $version, '/' ) . '\]?[^\n]*\n(.*?)(?=^## |\z)/ms';
	if ( ! preg_match( $re, $text, $m ) || '' === trim( $m[1] ) ) {
		fail( "CHANGELOG.md has no section for $version" );
	}
	return trim( $m[1] );
}

function manifest_json( string $root, string $version, string $sha256, string $changelog ): string {
	$plugin   = "$root/" . PLUGIN_DIR;
	$manifest = array(
		'version'      => $version,
		'zip_url'      => Manifest::ZIP_PREFIX . "v$version/fc-inventory.zip",
		'sha256'       => $sha256,
		'requires'     => read_header( "$plugin/fc-inventory.php", 'Requires at least' ),
		'requires_php' => read_header( "$plugin/fc-inventory.php", 'Requires PHP' ),
		'tested'       => read_header( "$plugin/readme.txt", 'Tested up to' ),
		'changelog'    => $changelog,
	);
	return json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
}

/** Loads the secret key and checks it matches the compiled-in public key. */
function signing_key( ?string $key_file ): string {
	$b64 = null !== $key_file ? @file_get_contents( $key_file ) : getenv( 'FC_INVENTORY_SIGNING_KEY' );
	if ( false === $b64 || '' === trim( (string) $b64 ) ) {
		fail( 'no signing key: pass --key-file or set FC_INVENTORY_SIGNING_KEY' );
	}
	$secret = base64_decode( trim( $b64 ), true );
	if ( false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
		fail( 'signing key is not a base64 Ed25519 secret key (64 bytes)' );
	}
	if ( base64_encode( sodium_crypto_sign_publickey_from_secretkey( $secret ) ) !== Manifest::RELEASE_PUBKEY ) {
		fail( 'signing key does not match Manifest::RELEASE_PUBKEY' );
	}
	return $secret;
}

function write_file( string $path, string $content ): void {
	if ( false === file_put_contents( $path, $content ) ) {
		fail( "cannot write $path" );
	}
	info( "wrote $path" );
}

function main( string $root ): void {
	$args   = parse_args();
	$secret = signing_key( $args['key_file'] );
	check_version( $root, $args['version'] );
	if ( ! is_dir( $args['out'] ) && ! mkdir( $args['out'], 0755, true ) ) {
		fail( "cannot create {$args['out']}" );
	}
	$zip = $args['out'] . '/fc-inventory.zip';
	build_zip( $root, $zip );
	info( "wrote $zip" );
	$changelog = changelog_section( $root, $args['version'] );
	$json      = manifest_json( $root, $args['version'], hash_file( 'sha256', $zip ), $changelog );
	$signature = base64_encode( sodium_crypto_sign_detached( $json, $secret ) );
	if ( null === Manifest::parse( $json, $signature ) ) {
		fail( 'self-check failed: the plugin would reject this manifest' );
	}
	write_file( $args['out'] . '/' . Manifest::MANIFEST, $json );
	write_file( $args['out'] . '/' . Manifest::SIGNATURE, $signature . "\n" );
	write_file( $args['out'] . '/release-notes.md', $changelog . "\n" );
}

main( $root );
