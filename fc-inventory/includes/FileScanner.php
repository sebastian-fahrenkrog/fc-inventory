<?php
namespace FcInventory;

defined( 'ABSPATH' ) || defined( 'FC_INVENTORY_TESTING' ) || exit;

/**
 * Reads core, plugin, theme, mu-plugin, drop-in and Composer versions from
 * the files, with the same rules as the collector's internal/sbom/wordpress.go
 * (get_file_data-style headers in the first 8 KiB). No database access.
 */
final class FileScanner {
	private const HEADER_BYTES   = 8192;
	private const MAX_JSON_BYTES = 10485760;
	private const HEADER_RE      = '/^[ \t\/*#@]*(Plugin Name|Theme Name|Version|Template):(.*)$/mi';
	private const CORE_RE        = '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/';

	/** WordPress' known drop-ins (_get_dropins()), including the multisite ones. */
	public const DROPINS = array(
		'advanced-cache.php',
		'db.php',
		'db-error.php',
		'install.php',
		'maintenance.php',
		'object-cache.php',
		'php-error.php',
		'fatal-error-handler.php',
		'sunrise.php',
		'blog-deleted.php',
		'blog-inactive.php',
		'blog-suspended.php',
	);

	public function __construct(
		private string $abspath,
		private string $content_dir,
		private string $plugin_dir,
		private string $mu_plugin_dir,
		private string $theme_dir
	) {
	}

	/** Core version from wp-includes/version.php, '' if unreadable. */
	public function coreVersion(): string {
		$data = @file_get_contents( $this->abspath . '/wp-includes/version.php' );
		if ( false === $data || 1 !== preg_match( self::CORE_RE, $data, $m ) ) {
			return '';
		}
		return $m[1];
	}

	/**
	 * Directory plugins (<slug>/<any>.php with a Plugin Name header, first in
	 * byte order) and single-file plugins (<slug>.php).
	 *
	 * @return list<array{slug: string, file: string, name: string, version: string, single_file: bool, dir: string}>
	 */
	public function plugins(): array {
		$out = array();
		foreach ( self::listDir( $this->plugin_dir ) as $entry ) {
			$plugin = $this->plugin( $entry );
			if ( null !== $plugin ) {
				$out[] = $plugin;
			}
		}
		return $out;
	}

	private function plugin( string $entry ): ?array {
		$path = $this->plugin_dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			return $this->directoryPlugin( $entry, $path );
		}
		if ( ! str_ends_with( $entry, '.php' ) ) {
			return null;
		}
		$h = self::readHeaders( $path );
		if ( '' === ( $h['Plugin Name'] ?? '' ) ) {
			return null;
		}
		return self::pluginRow( self::singleFileSlug( $entry ), $entry, $h, true, '' );
	}

	private function directoryPlugin( string $entry, string $path ): ?array {
		foreach ( self::listDir( $path ) as $file ) {
			if ( ! str_ends_with( $file, '.php' ) || ! is_file( $path . '/' . $file ) ) {
				continue;
			}
			$h = self::readHeaders( $path . '/' . $file );
			if ( '' !== ( $h['Plugin Name'] ?? '' ) ) {
				return self::pluginRow( $entry, $entry . '/' . $file, $h, false, $path );
			}
		}
		return null;
	}

	private static function pluginRow( string $slug, string $file, array $h, bool $single, string $dir ): array {
		return array(
			'slug'        => $slug,
			'file'        => $file,
			'name'        => $h['Plugin Name'] ?? '',
			'version'     => $h['Version'] ?? '',
			'single_file' => $single,
			'dir'         => $dir,
		);
	}

	/**
	 * Maps a single-file plugin to its wordpress.org slug. Hello Dolly is the
	 * one bundled exception: hello.php is published as hello-dolly.
	 */
	public static function singleFileSlug( string $file ): string {
		$slug = substr( $file, 0, -strlen( '.php' ) );
		return 'hello' === $slug ? 'hello-dolly' : $slug;
	}

	/**
	 * Theme directories with a style.css that has a Theme Name header.
	 *
	 * @return list<array{slug: string, name: string, version: string, parent: string, dir: string}>
	 */
	public function themes(): array {
		$out = array();
		foreach ( self::listDir( $this->theme_dir ) as $entry ) {
			$dir = $this->theme_dir . '/' . $entry;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$h = self::readHeaders( $dir . '/style.css' );
			if ( '' === ( $h['Theme Name'] ?? '' ) ) {
				continue;
			}
			$out[] = array(
				'slug'    => $entry,
				'name'    => $h['Theme Name'],
				'version' => $h['Version'] ?? '',
				'parent'  => $h['Template'] ?? '',
				'dir'     => $dir,
			);
		}
		return $out;
	}

	/**
	 * Top-level PHP files in mu-plugins (WordPress loads only those).
	 *
	 * @return list<array{file: string, name: string, version: string}>
	 */
	public function muPlugins(): array {
		$out = array();
		foreach ( self::listDir( $this->mu_plugin_dir ) as $entry ) {
			$path = $this->mu_plugin_dir . '/' . $entry;
			if ( str_ends_with( $entry, '.php' ) && is_file( $path ) ) {
				$out[] = self::fileRow( $entry, $path );
			}
		}
		return $out;
	}

	/**
	 * @return list<array{file: string, name: string, version: string}>
	 */
	public function dropins(): array {
		$out = array();
		foreach ( self::DROPINS as $file ) {
			$path = $this->content_dir . '/' . $file;
			if ( is_file( $path ) ) {
				$out[] = self::fileRow( $file, $path );
			}
		}
		return $out;
	}

	private static function fileRow( string $file, string $path ): array {
		$h = self::readHeaders( $path );
		return array(
			'file'    => $file,
			'name'    => $h['Plugin Name'] ?? '',
			'version' => $h['Version'] ?? '',
		);
	}

	/**
	 * Packages from <dir>/vendor/composer/installed.json (Composer 1 list or
	 * Composer 2 object). Null if the install tree is not there.
	 *
	 * @return list<array{name: string, version: string}>|null
	 */
	public static function composerPackages( string $dir ): ?array {
		$path = $dir . '/vendor/composer/installed.json';
		if ( '' === $dir || ! is_file( $path ) || filesize( $path ) > self::MAX_JSON_BYTES ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$list = isset( $data['packages'] ) && is_array( $data['packages'] ) ? $data['packages'] : $data;
		return array_is_list( $list ) ? self::packageRows( $list ) : array();
	}

	private static function packageRows( array $list ): array {
		$out = array();
		foreach ( $list as $p ) {
			if ( is_array( $p ) && is_string( $p['name'] ?? null ) && is_string( $p['version'] ?? null ) ) {
				$out[] = array( 'name' => $p['name'], 'version' => $p['version'] );
			}
		}
		return $out;
	}

	/**
	 * Parses the first 8 KiB like WordPress' get_file_data(): the first match
	 * per header wins, values are cut at "*\/" and "?>" and trimmed.
	 *
	 * @return array<string,string>
	 */
	public static function readHeaders( string $path ): array {
		$data = is_file( $path ) ? @file_get_contents( $path, false, null, 0, self::HEADER_BYTES ) : false;
		if ( false === $data || ! preg_match_all( self::HEADER_RE, $data, $matches, PREG_SET_ORDER ) ) {
			return array();
		}
		$out = array();
		foreach ( $matches as $m ) {
			$key = self::canonicalHeader( $m[1] );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = self::cleanHeaderValue( $m[2] );
			}
		}
		return $out;
	}

	private static function canonicalHeader( string $key ): string {
		return match ( strtolower( $key ) ) {
			'plugin name' => 'Plugin Name',
			'theme name'  => 'Theme Name',
			'template'    => 'Template',
			default       => 'Version',
		};
	}

	/** Matches WordPress' _cleanup_header_comment(). */
	public static function cleanHeaderValue( string $value ): string {
		$value = trim( $value );
		foreach ( array( '*/', '?>' ) as $end ) {
			$pos = strpos( $value, $end );
			if ( false !== $pos ) {
				$value = substr( $value, 0, $pos );
			}
		}
		return trim( $value );
	}

	/**
	 * Directory entries in byte order (like Go's os.ReadDir), without . and ..
	 *
	 * @return list<string>
	 */
	public static function listDir( string $dir ): array {
		$entries = is_dir( $dir ) ? @scandir( $dir, SCANDIR_SORT_NONE ) : false;
		if ( false === $entries ) {
			return array();
		}
		$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
		sort( $entries, SORT_STRING );
		return $entries;
	}
}
