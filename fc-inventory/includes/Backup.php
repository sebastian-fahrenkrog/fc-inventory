<?php
namespace FcInventory;

defined( 'ABSPATH' ) || exit;

/**
 * ZIP copies of a plugin or theme taken before a change, and their restore.
 *
 * Directory: the constant FC_INVENTORY_BACKUP_DIR if defined (ideally outside
 * the web root), otherwise wp-content/fc-inventory-backups-<random token>.
 * The token keeps the default directory unguessable on servers that ignore
 * the .htaccess written into it (nginx).
 */
final class Backup {
	public const TOKEN_OPTION = 'fc_inventory_backup_token';
	public const CONSTANT     = 'FC_INVENTORY_BACKUP_DIR';
	private const KEEP        = 3;

	public static function directory(): string {
		if ( defined( self::CONSTANT ) && is_string( constant( self::CONSTANT ) ) && '' !== constant( self::CONSTANT ) ) {
			return untrailingslashit( constant( self::CONSTANT ) );
		}
		$token = get_option( self::TOKEN_OPTION );
		if ( ! is_string( $token ) || 1 !== preg_match( '/^[0-9a-f]{24}$/D', $token ) ) {
			$token = bin2hex( random_bytes( 12 ) );
			update_option( self::TOKEN_OPTION, $token, false );
		}
		return WP_CONTENT_DIR . '/fc-inventory-backups-' . $token;
	}

	/**
	 * Zips $path (a directory or a single file) and returns the archive path.
	 *
	 * @throws \RuntimeException if the copy cannot be written
	 */
	public static function create( string $kind, string $slug, string $version, string $path ): string {
		$dir = self::prepareDirectory();
		$zip = sprintf( '%s/%s--%s--%s--%s.zip', $dir, $kind, $slug, preg_replace( '/[^A-Za-z0-9._-]/', '_', $version ), gmdate( 'Ymd-His' ) );
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'ZipArchive is missing, no backup possible' );
		}
		$archive = new \ZipArchive();
		if ( true !== $archive->open( $zip, \ZipArchive::CREATE | \ZipArchive::EXCL ) ) {
			throw new \RuntimeException( 'cannot create the backup archive' );
		}
		self::addPath( $archive, $path );
		if ( ! $archive->close() || ! is_file( $zip ) ) {
			throw new \RuntimeException( 'cannot write the backup archive' );
		}
		self::prune( $dir, $kind . '--' . $slug . '--' );
		return $zip;
	}

	/** Replaces $path with the content of the archive taken from it. */
	public static function restore( string $zip, string $path ): bool {
		global $wp_filesystem;
		if ( file_exists( $path ) && ! $wp_filesystem->delete( $path, true ) ) {
			return false;
		}
		return true === unzip_file( $zip, dirname( $path ) );
	}

	private static function prepareDirectory(): string {
		$dir = self::directory();
		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			throw new \RuntimeException( 'backup directory is not writable' );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	private static function addPath( \ZipArchive $archive, string $path ): void {
		$root = basename( $path );
		if ( is_file( $path ) ) {
			$archive->addFile( $path, $root );
			return;
		}
		$archive->addEmptyDir( $root );
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $items as $item ) {
			$local = $root . '/' . str_replace( '\\', '/', substr( $item->getPathname(), strlen( $path ) + 1 ) );
			$item->isDir() ? $archive->addEmptyDir( $local ) : $archive->addFile( $item->getPathname(), $local );
		}
	}

	/** Keeps the newest archives of one plugin or theme. */
	private static function prune( string $dir, string $prefix ): void {
		$files = glob( $dir . '/' . $prefix . '*.zip' );
		if ( ! is_array( $files ) ) {
			return;
		}
		usort( $files, static fn( string $a, string $b ): int => filemtime( $b ) <=> filemtime( $a ) );
		foreach ( array_slice( $files, self::KEEP ) as $old ) {
			wp_delete_file( $old );
		}
	}
}
