<?php

namespace MediaWiki\Extension\BlockInactive;

use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive
 * @group Database
 * @group medium
 */
class BlockInactiveTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::getQuery
	 */
	public function testGetQuery() {
		$threshold = 10000;
		$ret = BlockInactive::getInstance()->getQuery(
			$threshold
		);
		$time = time();
		$this->assertEquals(
			'u.user_touched <= ' . wfTimestamp( TS_MW, $time - $threshold ),
			$ret['conds'][0]
		);
	}

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::skipUser
	 */
	public function testSkipUser() {
		$this->assertFalse(
			BlockInactive::getInstance()->skipUser(
				$this->getTestUser( [] )->getUser()
			)
		);
		$this->assertTrue(
			BlockInactive::getInstance()->skipUser(
				$this->getTestUser( [ 'sysop' ] )->getUser()
			)
		);
	}

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::getInactiveUsers
	 */
	public function testGetInactiveUsers() {
		// Clean up the test user database (that's safe)
		$this->getDb()->delete( 'user', 'user_id IS NOT NULL' );
		// Expected zero
		$inactiveUsersInitial = BlockInactive::getInstance()->getInactiveUsers(
			1
		);
		$this->assertCount(
			0,
			$inactiveUsersInitial
		);
		// Add new user last touched 1 hour ago
		$u = $this->getMutableTestUser()->getUser();
		$u->checkAndSetTouched();
		$u->saveSettings();
		// Shift the user touched flag by 1 hour
		$this->getDb()->update( 'user', [
				'user_touched' => wfTimestamp( TS_MW, time() - 3600 )
			], [
				'user_id' => $u->getId()
			] );
		// Add new user last touched 10 minutes ago
		$u = $this->getMutableTestUser()->getUser();
		$u->checkAndSetTouched();
		$u->saveSettings();
		// Shift the user touched flag by 10 minutes
		$this->getDb()->update( 'user', [
				'user_touched' => wfTimestamp( TS_MW, time() - 600 )
			], [
				'user_id' => $u->getId()
			] );
		// Add new user last touched 2 hours ago, but with an 'alwaysactive' right
		$u = $this->getMutableTestUser( [ 'sysop' ] )->getUser();
		$u->checkAndSetTouched();
		$u->saveSettings();
		// Shift the user touched flag by 2 hours
		$this->getDb()->update( 'user', [
				'user_touched' => wfTimestamp( TS_MW, time() - 7200 )
			], [
				'user_id' => $u->getId()
			] );
		// Fetch user considering half-an-hour as an inactive threshold
		$inactiveUsers = BlockInactive::getInstance()->getInactiveUsers(
			1800
		);
		// Expect the number of inactive users to grow by 1
		$this->assertCount(
			1,
			$inactiveUsers,
			"test sysop + 1 inactive user"
		);
		// Fetch user considering 5 minutes as an inactive threshold
		$inactiveUsers = BlockInactive::getInstance()->getInactiveUsers(
			300
		);

		// Expect the number of inactive users to grow by 2
		$this->assertCount(
			2,
			$inactiveUsers,
			"test sysop + 2 inactive user"
		);
		// Add new user last touched 1 hour ago
		$u = $this->getMutableTestUser()->getUser();
		$u->checkAndSetTouched();
		$u->saveSettings();
		$this->getDb()->update( 'user', [
				'user_touched' => wfTimestamp( TS_MW, time() - 3600 )
			], [
				'user_id' => $u->getId()
			] );
		// Do an edit with this user
		$this->insertPage(
			"BlockInactiveUnitTest1",
			"Unit testing page with test content.",
			NS_MAIN,
			$u
		);
		$inactiveUsers = BlockInactive::getInstance()->getInactiveUsers(
			1800
		);
		$this->assertCount(
			1,
			$inactiveUsers,
			"after edit, user considered active"
		);
	}

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::getThreshold
	 */
	public function testGetThreshold() {
		$this->overrideConfigValues( [
			'BlockInactiveThreshold' => 123,
		] );
		$this->assertEquals(
			123 * 60 * 60 * 24,
			BlockInactive::getInstance()->getThreshold()
		);
	}

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::getWarningSchedule
	 */
	public function testGetWarningSchedule() {
		$this->overrideConfigValues( [
			'BlockInactiveWarningDaysLeft' => [ 1, 2, 3, 4, 5 ],
		] );
		$this->assertEquals(
			[ 1, 2, 3, 4, 5 ],
			BlockInactive::getInstance()->getWarningSchedule()
		);
	}

	public function testGetBlockTime() {
		$this->overrideConfigValues( [
			'BlockInactiveDaysBlock' => 123,
		] );
		$this->assertEquals(
			123 * 60 * 60 * 24,
			BlockInactive::getInstance()->getBlockTime()
		);
	}

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::timeLeft
	 */
	public function testTimeLeft() {
		$this->overrideConfigValues( [
			'BlockInactiveThreshold' => 7, // days
			'BlockInactiveDaysBlock' => 14, // days
		] );
		// Clean up the test user database (that's safe)
		$this->getDb()->delete( 'user', 'user_id IS NOT NULL' );
		$u = $this->getUserWithTouched( 3600 * 24 * 10 );
		$blockTime = BlockInactive::getInstance()->timeLeft( $u );
		$this->assertTrue(
			$blockTime >= 3600 * 24 * 4 - 100 && $blockTime < 3600 * 24 * 4 + 100
		);
		$u = $this->getUserWithTouched( 3600 * 24 * 30 );
		$blockTime = BlockInactive::getInstance()->timeLeft( $u );
		$this->assertTrue(
			$blockTime < 0
		);
	}

	/**
	 * Creates a test user with a given touched date
	 *
	 * @param int $touched
	 *
	 * @return User
	 */
	private function getUserWithTouched( $touched ): User {
		$u = $this->getMutableTestUser()->getUser();
		$u->saveSettings();
		$this->getDb()->update( 'user', [
				'user_touched' => wfTimestamp( TS_MW, time() - $touched )
			], [
				'user_id' => $u->getId()
			] );
		$u->clearInstanceCache();
		$u->loadFromDatabase();
		return $u;
	}

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::daysLeft
	 */
	public function testDaysLeft() {
		$this->overrideConfigValues( [
			'BlockInactiveThreshold' => 7, // days
			'BlockInactiveDaysBlock' => 14, // days
		] );
		// Clean up the test user database (that's safe)
		$this->getDb()->delete( 'user', 'user_id IS NOT NULL' );
		$u = $this->getUserWithTouched( 3600 * 24 * 10 );
		$daysLeft = BlockInactive::getInstance()->daysLeft( $u );
		// Test that the blocktime matches 7 days roughly
		$this->assertEquals(
			4,
			$daysLeft
		);
		$u = $this->getUserWithTouched( 3600 * 24 * 18 );
		$daysLeft = BlockInactive::getInstance()->daysLeft( $u );
		// Test that the blocktime matches 7 days roughly
		$this->assertEquals(
			-4,
			$daysLeft
		);
	}

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::getUserBlockTime
	 */
	public function testGetUserBlockTime() {
		$this->overrideConfigValues( [
			'BlockInactiveThreshold' => 7, // days
			'BlockInactiveDaysBlock' => 14, // days
		] );
		// Clean up the test user database (that's safe)
		$this->getDb()->delete( 'user', 'user_id IS NOT NULL' );
		$u = $this->getUserWithTouched( 3600 * 24 * 10 );
		$blockTime = BlockInactive::getInstance()->getUserBlockTime( $u );
		// Test that the blocktime matches 7 days roughly
		$this->assertTrue(
			$blockTime >= time() + 3600 * 24 * 3 && $blockTime < time() + 3600 * 24 * 5
		);
	}

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::blockUser
	 */
	public function testBlockUser() {
		// Ignores invalid
		$this->assertFalse(
			BlockInactive::getInstance()->blockUser(
				$this->getServiceContainer()->getUserFactory()->newFromId( 0 )
			)
		);
		// Clean up the test user database (that's safe)
		$this->getDb()->delete( 'user', 'user_id IS NOT NULL' );
		// Add new user last touched 1 hour ago
		$u = $this->getMutableTestUser()->getUser();
		BlockInactive::getInstance()->blockUser( $u );
		$this->assertNotNull(
			$u->getBlock()
		);
		// Can't block twice
		$this->assertFalse(
			BlockInactive::getInstance()->blockUser( $u )
		);
	}

	public function testMatchesSchedule() {
		$this->overrideConfigValues( [
			'BlockInactiveThreshold' => 7, // days
			'BlockInactiveDaysBlock' => 14, // days
			'BlockInactiveWarningDaysLeft' => [ 1, 3, 5 ], // schedule
		] );
		// Clean up the test user database (that's safe)
		$this->getDb()->delete( 'user', 'user_id IS NOT NULL' );
		$u = $this->getUserWithTouched( 3600 * 24 * 9 );
		$this->assertTrue(
			BlockInactive::getInstance()->matchesSchedule( $u )
		);
		$u = $this->getUserWithTouched( 3600 * 24 * 10 );
		$this->assertFalse(
			BlockInactive::getInstance()->matchesSchedule( $u )
		);
		$u = $this->getUserWithTouched( 3600 * 24 * 13 );
		$this->assertTrue(
			BlockInactive::getInstance()->matchesSchedule( $u )
		);
		$u = $this->getUserWithTouched( 3600 * 24 * 11 );
		$this->assertTrue(
			BlockInactive::getInstance()->matchesSchedule( $u )
		);
	}

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::hasPendingWarnings
	 */
	public function testHasPendingWarnings() {
		$this->overrideConfigValues( [
			'BlockInactiveThreshold' => 7, // days
			'BlockInactiveDaysBlock' => 14, // days
			'BlockInactiveWarningDaysLeft' => [ 1, 3, 5 ], // schedule
		] );
		// Clean up the test user database (that's safe)
		$this->getDb()->delete( 'user', 'user_id IS NOT NULL' );
		$u = $this->getUserWithTouched( 3600 * 24 * 35 );
		$this->assertTrue(
			BlockInactive::getInstance()->hasPendingWarnings( $u )
		);
		$u = $this->getUserWithTouched( 3600 * 24 * 5 );
		$this->assertFalse(
			BlockInactive::getInstance()->hasPendingWarnings( $u )
		);
		$u = $this->getUserWithTouched( 3600 * 24 * 14 );
		$this->assertTrue(
			BlockInactive::getInstance()->hasPendingWarnings( $u )
		);
	}

	/**
	 * @covers \MediaWiki\Extension\BlockInactive\BlockInactive::getWarningsMissed
	 */
	public function testGetWarningsMissed() {
		$this->overrideConfigValues( [
			'BlockInactiveThreshold' => 7, // days
			'BlockInactiveDaysBlock' => 14, // days
			'BlockInactiveWarningDaysLeft' => [ 1, 3, 5 ], // schedule
		] );
		// Clean up the test user database (that's safe)
		$this->getDb()->delete( 'user', 'user_id IS NOT NULL' );
		$u = $this->getUserWithTouched( 3600 * 24 * 30 );
		$this->assertArrayEquals(
			[
				[
					'day' => 1,
					'ts' => wfTimestamp( TS_UNIX, $u->getDBTouched() ) + 3600 * 24 * 13
				],
				[
					'day' => 3,
					'ts' => wfTimestamp( TS_UNIX, $u->getDBTouched() ) + 3600 * 24 * 11
				],
				[
					'day' => 5,
					'ts' => wfTimestamp( TS_UNIX, $u->getDBTouched() ) + 3600 * 24 * 9
				]
			],
			BlockInactive::getInstance()->getWarningsMissed( $u )
		);
		// Simulate the warning was sent 5 day before, but never again
		BlockInactiveMailRecord::insert(
			$u->getId(),
			'test@localhost',
			BlockInactiveMailRecord::MAIL_TYPE_WARNING,
			wfTimestamp( TS_UNIX, $u->getDBTouched() ) + 3600 * 24 * 9
		);
		$this->assertArrayEquals(
			[
				[
					'day' => 1,
					'ts' => wfTimestamp( TS_UNIX, $u->getDBTouched() ) + 3600 * 24 * 13
				],
				[
					'day' => 3,
					'ts' => wfTimestamp( TS_UNIX, $u->getDBTouched() ) + 3600 * 24 * 11
				]
			],
			BlockInactive::getInstance()->getWarningsMissed( $u )
		);
		// Simulate the warning was sent 3 and 5 day before, but never again
		BlockInactiveMailRecord::insert(
			$u->getId(),
			'test@localhost',
			BlockInactiveMailRecord::MAIL_TYPE_WARNING,
			wfTimestamp( TS_UNIX, $u->getDBTouched() ) + 3600 * 24 * 11
		);
		$this->assertArrayEquals(
			[
				[
					'day' => 1,
					'ts' => wfTimestamp( TS_UNIX, $u->getDBTouched() ) + 3600 * 24 * 13
				]
			],
			BlockInactive::getInstance()->getWarningsMissed( $u )
		);
		// Get a new user
		$u = $this->getUserWithTouched( 3600 * 24 * 30 );
		// And simulate we did send warnings on day 1 and day 3 but no warning were sent on day 3
		BlockInactiveMailRecord::insert(
			$u->getId(),
			'test@localhost',
			BlockInactiveMailRecord::MAIL_TYPE_WARNING,
			wfTimestamp( TS_UNIX, $u->getDBTouched() ) + 3600 * 24 * 13
		);
		BlockInactiveMailRecord::insert(
			$u->getId(),
			'test@localhost',
			BlockInactiveMailRecord::MAIL_TYPE_WARNING,
			wfTimestamp( TS_UNIX, $u->getDBTouched() ) + 3600 * 24 * 11
		);
		// The in-the-middle warning need to be ignored and NOT considered as pending
		$this->assertArrayEquals(
			[],
			BlockInactive::getInstance()->getWarningsMissed( $u )
		);
	}

	/**
	 * test for test
	 */
	public function testGetUsersWithTouched() {
		$u = $this->getUserWithTouched( 3600 * 24 * 7 );
		$msTouched = $u->getDBTouched();
		$uxTouched = wfTimestamp( TS_UNIX, $msTouched );
		$this->assertTrue(
			$uxTouched < time() - 3600 * 24 * 6
		);
	}

	// TODO: add test for daysLeft method supply it with provider
	// covering couple of days range to check if it correctly calculates
	// days left and not outputting the same result twice on different real
	// days

}
