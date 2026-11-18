<?php
namespace FcInventory\Tests;

use FcInventory\CoreOffer;
use PHPUnit\Framework\TestCase;

/**
 * Which core version an update_core order installs.
 */
final class CoreOfferTest extends TestCase {
	public function testBranch(): void {
		$this->assertSame( '6.8', CoreOffer::branch( '6.8.3' ) );
		$this->assertSame( '6.8', CoreOffer::branch( '6.8' ) );
		$this->assertSame( '7.0', CoreOffer::branch( '7' ) );
	}

	public function testStaysOnTheBranchByDefault(): void {
		$pick = CoreOffer::pick( '6.7.2', array( '7.1.4', '6.7.5', '6.7.4' ), false );
		$this->assertSame( array( 'version' => '6.7.5', 'newer_branch' => '7.1.4' ), $pick );
	}

	public function testReportsANewerBranchWithoutInstallingIt(): void {
		$pick = CoreOffer::pick( '6.7.5', array( '7.1.4' ), false );
		$this->assertSame( array( 'version' => null, 'newer_branch' => '7.1.4' ), $pick );
	}

	public function testMajorTakesTheNewestOffer(): void {
		$pick = CoreOffer::pick( '6.7.2', array( '6.7.5', '7.1.4', '7.0.9' ), true );
		$this->assertSame( array( 'version' => '7.1.4', 'newer_branch' => null ), $pick );
	}

	public function testNothingNewer(): void {
		$empty = array( 'version' => null, 'newer_branch' => null );
		$this->assertSame( $empty, CoreOffer::pick( '7.1.4', array( '7.1.4', '7.1.3' ), false ) );
		$this->assertSame( $empty, CoreOffer::pick( '7.1.4', array(), true ) );
	}
}
