<?php
namespace FcInventory\Tests;

use FcInventory\ActionRequest;
use FcInventory\InvalidAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Body validation of contract action-v1: only exact, known orders pass.
 */
final class ActionRequestTest extends TestCase {
	public function testPluginUpdate(): void {
		$r = ActionRequest::fromJson( '{"schema":1,"action":"update_plugin","slug":"akismet"}' );
		$this->assertSame( array( 'update_plugin', 'akismet', false ), array( $r->action, $r->slug, $r->allow_major ) );
	}

	public function testSingleFilePluginAndThemeSlugs(): void {
		$this->assertSame( 'hello', ActionRequest::fromJson( '{"schema":1,"action":"deactivate_plugin","slug":"hello"}' )->slug );
		$this->assertSame( 'Divi_5.child', ActionRequest::fromJson( '{"schema":1,"action":"update_theme","slug":"Divi_5.child"}' )->slug );
	}

	public function testCoreUpdateDefaultsToSameBranch(): void {
		$this->assertFalse( ActionRequest::fromJson( '{"schema":1,"action":"update_core"}' )->allow_major );
		$this->assertTrue( ActionRequest::fromJson( '{"schema":1,"action":"update_core","allow_major":true}' )->allow_major );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function invalidBodies(): array {
		return array(
			'not json'            => array( 'update', 'body is not a JSON object' ),
			'list'                => array( '[1]', 'body is not a JSON object' ),
			'empty object'        => array( '{}', 'body is not a JSON object' ),
			'wrong schema'        => array( '{"schema":2,"action":"update_core"}', 'unsupported schema' ),
			'schema as string'    => array( '{"schema":"1","action":"update_core"}', 'unsupported schema' ),
			'unknown action'      => array( '{"schema":1,"action":"install_plugin","slug":"x"}', 'unknown action' ),
			'unknown field'       => array( '{"schema":1,"action":"update_plugin","slug":"x","url":"http://evil/x.zip"}', 'unknown field: url' ),
			'missing slug'        => array( '{"schema":1,"action":"update_plugin"}', 'missing or malformed slug' ),
			'path traversal'      => array( '{"schema":1,"action":"update_plugin","slug":"../x"}', 'missing or malformed slug' ),
			'double dot'          => array( '{"schema":1,"action":"update_theme","slug":"a..b"}', 'missing or malformed slug' ),
			'leading dot'         => array( '{"schema":1,"action":"update_theme","slug":".git"}', 'missing or malformed slug' ),
			'slash'               => array( '{"schema":1,"action":"deactivate_plugin","slug":"a/b.php"}', 'missing or malformed slug' ),
			'slug with newline'   => array( '{"schema":1,"action":"update_plugin","slug":"abc\n"}', 'missing or malformed slug' ),
			'slug on core'        => array( '{"schema":1,"action":"update_core","slug":"x"}', 'slug is not allowed for this action' ),
			'major on plugin'     => array( '{"schema":1,"action":"update_plugin","slug":"x","allow_major":true}', 'allow_major must be a boolean and is only valid for update_core' ),
			'major not a boolean' => array( '{"schema":1,"action":"update_core","allow_major":1}', 'allow_major must be a boolean and is only valid for update_core' ),
		);
	}

	#[DataProvider( 'invalidBodies' )]
	public function testRejects( string $body, string $reason ): void {
		try {
			ActionRequest::fromJson( $body );
			$this->fail( 'body was accepted' );
		} catch ( InvalidAction $e ) {
			$this->assertSame( $reason, $e->getMessage() );
		}
	}
}
