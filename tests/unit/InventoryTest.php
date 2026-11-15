<?php
namespace FcInventory\Tests;

use FcInventory\FileScanner;
use FcInventory\Inventory;
use PHPUnit\Framework\TestCase;

/**
 * Inventory of the fixture tree tests/fixtures/wp, read with the rules of
 * the collector's internal/sbom/wordpress.go.
 */
final class InventoryTest extends TestCase {
	private static function scanner(): FileScanner {
		$root    = dirname( __DIR__ ) . '/fixtures/wp';
		$content = $root . '/wp-content';
		return new FileScanner( $root, $content, $content . '/plugins', $content . '/mu-plugins', $content . '/themes' );
	}

	private static function inventory(): array {
		return Inventory::build(
			self::scanner(),
			array(
				'plugin_version'  => '1.0.0',
				'generated_at'    => '2026-11-15T10:00:00Z',
				'site_url'        => 'https://www.example.com',
				'home_url'        => 'https://www.example.com',
				'multisite'       => false,
				'active_plugins'  => array( 'akismet/akismet.php', 'hello.php' ),
				'network_plugins' => array( 'with-composer/with-composer.php' ),
				'stylesheet'      => 'child-theme',
				'template'        => 'parent-theme',
				'env'             => array( 'php' => '8.3.0', 'sapi' => 'cli', 'db' => '', 'server' => '' ),
			)
		);
	}

	private static function bySlug( array $rows ): array {
		return array_column( $rows, null, 'slug' );
	}

	public function testTopLevelFields(): void {
		$inv = self::inventory();
		$this->assertSame( 1, $inv['schema'] );
		$this->assertSame( '6.8.3', $inv['core'] );
		$this->assertSame( '1.0.0', $inv['plugin_version'] );
		$this->assertSame(
			array( 'schema', 'plugin_version', 'generated_at', 'site_url', 'home_url', 'multisite', 'core', 'plugins', 'mu_plugins', 'dropins', 'themes', 'composer', 'env' ),
			array_keys( $inv )
		);
	}

	public function testPluginsSlugsAndVersions(): void {
		$plugins = self::bySlug( self::inventory()['plugins'] );
		$this->assertSame( array( 'akismet', 'composer-one', 'hello-dolly', 'multi', 'with-composer' ), array_keys( $plugins ) );
		$this->assertSame( '5.5', $plugins['akismet']['version'] );
		$this->assertSame( 'hello.php', $plugins['hello-dolly']['file'] );
		$this->assertTrue( $plugins['hello-dolly']['single_file'] );
		$this->assertFalse( $plugins['akismet']['single_file'] );
	}

	public function testHeaderCleanupAndFirstMatchWins(): void {
		$multi = self::bySlug( self::inventory()['plugins'] )['multi'];
		$this->assertSame( 'multi/b-main.php', $multi['file'] );
		$this->assertSame( 'Multi Plugin', $multi['name'] );
		$this->assertSame( '2.0.0', $multi['version'] );
	}

	public function testActiveFlags(): void {
		$plugins = self::bySlug( self::inventory()['plugins'] );
		$this->assertTrue( $plugins['akismet']['active'] );
		$this->assertFalse( $plugins['akismet']['network_active'] );
		$this->assertTrue( $plugins['with-composer']['active'] );
		$this->assertTrue( $plugins['with-composer']['network_active'] );
		$this->assertFalse( $plugins['multi']['active'] );
	}

	public function testThemes(): void {
		$themes = self::bySlug( self::inventory()['themes'] );
		$this->assertSame( array( 'child-theme', 'parent-theme' ), array_keys( $themes ) );
		$this->assertSame( 'parent-theme', $themes['child-theme']['parent'] );
		$this->assertSame( '1.0.1', $themes['child-theme']['version'] );
		$this->assertTrue( $themes['child-theme']['active'] );
		$this->assertTrue( $themes['parent-theme']['active'] );
	}

	public function testMuPluginsAndDropins(): void {
		$inv = self::inventory();
		$this->assertSame(
			array(
				array( 'file' => 'hardening.php', 'name' => 'Site Hardening', 'version' => '1.0' ),
				array( 'file' => 'loader.php', 'name' => '', 'version' => '' ),
			),
			$inv['mu_plugins']
		);
		$this->assertSame(
			array(
				array( 'file' => 'advanced-cache.php', 'name' => 'Cache drop-in', 'version' => '3.1' ),
				array( 'file' => 'object-cache.php', 'name' => '', 'version' => '' ),
			),
			$inv['dropins']
		);
	}

	public function testComposerOnlyWithInstallTree(): void {
		$composer = array_column( self::inventory()['composer'], 'packages', 'owner' );
		$this->assertSame( array( 'plugin:composer-one', 'plugin:with-composer', 'theme:child-theme' ), array_keys( $composer ) );
		$this->assertSame( array( array( 'name' => 'twig/twig', 'version' => 'v2.15.0' ) ), $composer['plugin:composer-one'] );
		$this->assertSame(
			array(
				array( 'name' => 'guzzlehttp/guzzle', 'version' => '7.8.1' ),
				array( 'name' => 'psr/log', 'version' => 'v1.1.4' ),
			),
			$composer['plugin:with-composer']
		);
	}

	public function testMissingDirectoriesAreEmpty(): void {
		$scanner = new FileScanner( '/nonexistent', '/nonexistent', '/nonexistent/p', '/nonexistent/m', '/nonexistent/t' );
		$this->assertSame( '', $scanner->coreVersion() );
		$this->assertSame( array(), $scanner->plugins() );
		$this->assertSame( array(), $scanner->themes() );
		$this->assertSame( array(), $scanner->muPlugins() );
		$this->assertSame( array(), $scanner->dropins() );
	}

	public function testResponseEncodesAsJson(): void {
		$json = json_encode( self::inventory(), JSON_THROW_ON_ERROR );
		$this->assertStringContainsString( '"slug":"hello-dolly"', $json );
	}
}
