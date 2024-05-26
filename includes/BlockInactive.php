<?php

namespace MediaWiki\Extension\BlockInactive;

use ManualLogEntry;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\MediaWikiServices;
use MWException;
use SpecialBlock;
use User;

class BlockInactive {

	/** @var BlockInactive|null */
	private static $instance = null;

	/**
	 * @return BlockInactive
	 */
	public static function getInstance(): BlockInactive {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if the user need to be omitted (has the 'alwaysactive' right)
	 *
	 * @param User $user
	 *
	 * @return false
	 */
	public function skipUser( User $user ): bool {
		return MediaWikiServices::getInstance()
			->getPermissionManager()
			->userHasRight( $user, 'alwaysactive' );
	}

	/**
	 * @param int $threshold
	 *
	 * @return array
	 */
	public function getQuery( int $threshold ): array {
		$cutoff_timestamp = wfTimestamp( TS_MW, time() - $threshold );
		$dbr = wfGetDB( DB_REPLICA );

		$revision_max_ts_subquery = $dbr->buildSelectSubquery(
			[ 'r' => "revision" ],
			[ 'rev_actor', 'rev_max_ts' => 'MAX(rev_timestamp)' ],
			'',
			__METHOD__,
			[ 'GROUP BY' => 'rev_actor' ],
			[],
		);

		return [
			'tables' => [
				'u' => 'user',
				'e' => 'blockinactive_emails',
				'b' => 'ipblocks',
				'a' => 'actor',
				'r' => $revision_max_ts_subquery,
			],
			'fields' => [ 'user_id', 'MAX(ba_sent_ts) as ba_sent_ts', 'ipb_user' ],
			'conds' => [
				'u.user_touched <= ' . $cutoff_timestamp,  // not touched recently
				'b.ipb_user IS NULL',  // not already blocked
				'( rev_max_ts IS NULL OR rev_max_ts <= ' . $cutoff_timestamp . ')',
					// has not edited OR has not edited recently
			],
			'join_conds' => [
				'e' => [
					'LEFT JOIN',
					[
						"user_id = e.ba_user_id"
					]
				],
				'b' => [
					'LEFT JOIN',
					[
						"user_id = b.ipb_user"
					]
				],
				'a' => [
					'LEFT JOIN',
					[
						"user_id = a.actor_user"
					]
				],
				'r' => [
					'LEFT JOIN',
					[
						'r.rev_actor = a.actor_id'
					]
				]
			],
			'options' => [ 'GROUP BY' => [ 'u.user_id', 'ipb_user' ] ]
		];
	}

	/**
	 * @param int|null $threshold
	 *
	 * @return User[]
	 */
	public function getInactiveUsers( int $threshold = null ): array {
		if ( $threshold === null ) {
			$threshold = $this->getThreshold();
		}
		$dbr = wfGetDB( DB_REPLICA );
		$query = $this->getQuery( $threshold );
		$res = $dbr->select(
			$query['tables'],
			$query['fields'],
			$query['conds'],
			__METHOD__,
			$query['options'],
			$query['join_conds']
		);
		$results = [];
		foreach ( $res as $row ) {
			$user = User::newFromId( $row->user_id );
			// Exclude users with 'alwaysactive' right
			if (
				MediaWikiServices::getInstance()
					->getPermissionManager()
					->userHasRight( $user, 'alwaysactive' )
			) {
				continue;
			}
			$results[] = $user;
		}
		return $results;
	}

	/**
	 * Check if a given user can be considered as inactive
	 * @param User $user
	 *
	 * @return bool
	 */
	public function isInactive( User $user ): bool {
		return wfTimestamp( TS_UNIX, $user->getDBTouched() ) <= time() - $this->getThreshold();
	}

	/**
	 * @return int Threshold value in seconds
	 */
	public function getThreshold(): int {
		return (int)MediaWikiServices::getInstance()->getMainConfig()->get( 'BlockInactiveThreshold' )
			   * 60 * 60 * 24;
	}

	/**
	 * @return int[] Warning messages schedule
	 */
	public function getWarningSchedule(): array {
		return MediaWikiServices::getInstance()->getMainConfig()->get( 'BlockInactiveWarningDaysLeft' );
	}

	/**
	 * @return int Block time value in seconds
	 */
	public function getBlockTime(): int {
		return (int)MediaWikiServices::getInstance()->getMainConfig()->get( 'BlockInactiveDaysBlock' )
			   * 60 * 60 * 24;
	}

	/**
	 * How much time left until the user will be blocked
	 * @param User $user
	 *
	 * @return int time left in seconds
	 */
	public function timeLeft( User $user ): int {
		// The TS user was active
		$touched = (int)wfTimestamp( TS_UNIX, $user->getDBTouched() );
		// The number of seconds to block user after last active TS
		$blocktime = $this->getBlockTime();
		// The result is the difference between the two (can be negative)
		// literally block time vs time left since the user touched
		return $blocktime - ( time() - $touched );
	}

	/**
	 * @param User $user
	 *
	 * @return int number of days
	 */
	public function daysLeft( User $user ): int {
		return round( ( $this->timeLeft( $user ) ) / 60 / 60 / 24 );
	}

	/**
	 * @param User $user
	 *
	 * @return int UNIX timestamp
	 */
	public function getUserBlockTime( User $user ): int {
		return time() + $this->timeLeft( $user );
	}

	/**
	 * @param User $user
	 *
	 * @return bool
	 * @throws MWException
	 */
	public function blockUser( User $user ): bool {
		// User is invalid, skip
		if ( !$user->getId() ) {
			return false;
		}

		$priorBlock = DatabaseBlock::newFromTarget( $user );
		if ( $priorBlock === null ) {
			$block = new DatabaseBlock();
		} else {
			return false;
		}

		$performer = User::newFromId( 0 );
		$reason = 'Automatically blocked for inactivity';

		$block->setTarget( $user->getName() );
		$block->setBlocker( $performer );
		$block->setReason( $reason );
		$block->isHardblock( true );
		$block->isAutoblocking( false ); // Do not block the IPs
		$block->isCreateAccountBlocked( false ); // Do not block account creation
		$block->isEmailBlocked( false ); // Do not block emails
		$block->isUsertalkEditAllowed( false );
		$block->setExpiry( SpecialBlock::parseExpiryInput( 'infinity' ) );

		if ( $priorBlock === null ) {
			$success = $block->insert();
		} else {
			$success = $block->update();
		}

		if ( $success ) {
			// Fire any post block hooks
			MediaWikiServices::getInstance()->getHookContainer()->run(
				'BlockIpComplete',
				[
					$block, $performer, $priorBlock
				]
			);
			// Log it only if the block was successful
			$flags = [
				'nousertalk',
			];
			$logParams = [
				'5::duration' => 'indefinite',
				'6::flags' => implode( ',', $flags ),
			];

			$logEntry = new ManualLogEntry( 'block', 'block' );
			$logEntry->setTarget( $user->getUserPage() );
			$logEntry->setComment( $reason );
			$logEntry->setPerformer( $performer );
			$logEntry->setParameters( $logParams );
			$blockIds = array_merge( [ $success['id'] ], $success['autoIds'] );
			$logEntry->setRelations( [ 'ipb_id' => $blockIds ] );
			$logEntry->publish( $logEntry->insert() );

			return true;
		} else {
			return false;
		}
	}

	/**
	 * Checks if the given user matches the schedule for
	 * warning emails to be sent
	 *
	 * @param User $user
	 *
	 * @return bool
	 */
	public function matchesSchedule( User $user ): bool {
		$warningScheduleDaysLeft = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( 'BlockInactiveWarningDaysLeft' );
		$daysLeftUntilBlock = self::getInstance()
			->daysLeft( $user );
		return in_array( $daysLeftUntilBlock, $warningScheduleDaysLeft );
	}

	/**
	 * Fetches all the email records for a given user,
	 * optionally filtered by mail type
	 *
	 * @param User $user
	 * @param int|null $type
	 * @param int|null $ts
	 *
	 * @return BlockInactiveMailRecord[]
	 */
	public function getMailsSent( User $user, int $type = null, int $ts = null ): array {
		return BlockInactiveMailRecord::findForUserId( $user->getId(), 0, $type, $ts );
	}

	/**
	 * Checks if a given user has pending warnings to be sent out
	 *
	 * @param User $user
	 *
	 * @return bool
	 */
	public function hasPendingWarnings( User $user ): bool {
		if ( !$this->isInactive( $user ) ) {
			return false;
		}
		$warningScheduleDaysLeft = $this->getWarningSchedule();
		$warningToBeSent = count( $warningScheduleDaysLeft );
		$warningsSent = count( $this->getMailsSent( $user, BlockInactiveMailRecord::MAIL_TYPE_WARNING ) );
		if ( $warningsSent < $warningToBeSent ) {
			return true;
		}
		return false;
	}

	/**
	 * @param User $user
	 *
	 * @return array
	 */
	public function getWarningsMissed( User $user ): array {
		$warningsMissed = [];

		/**
		 * logic:
		 *
		 * if user has pending warnings (based on the schedule count and amount of warnings sent)
		 * then proceed, otherwise - break out
		 *
		 * user has less mails sent than the schedule implies
		 * maybe it's not time yet to send these pending mail, so check:
		 *
		 * for each schedule day check if that day as already passed or not
		 * if the day is already passed, then WARNING IS MISSED
		 *
		 * otherwise - the WARNING is NOT MISSED YET
		 *
		 * we ignore situation when the warning day is the today
		 * and do not consider it as MISSED
		 *
		 */

		// Can't have missed warnings if there are no pending warnings
		if ( !$this->hasPendingWarnings( $user ) ) {
			return $warningsMissed;
		}

		// Check each schedule entry against warnings sent out already
		$warningScheduleDaysLeft = $this->getWarningSchedule();

		foreach ( $warningScheduleDaysLeft as $dayLeft ) {
			// Determine if the warning day is in the past

			// The TS warning for the given day is intended to be sent
			$warningTs = $this->getUserBlockTime( $user ) - $dayLeft * ( 60 * 60 * 24 );
			// Clear out time factor from the TS
			// So we have a UNIX timestamp of the warning day
			$warningDate = strtotime( date( "Y-m-d", $warningTs ) );

			if ( $warningDate > time() ) {
				// The warning date is in the future, we have nothing to do with it
				continue;
			}

			if ( $warningDate == strtotime( date( "Y-m-d", time() ) ) ) {
				// If warning date is TODAY we not going do to anything with it
				continue;
			}

			// the warning is not in the future and is not today, so it's in the past
			// let's check if we did send it already on that date
			$warningsSent = $this->getMailsSent(
				$user,
				BlockInactiveMailRecord::MAIL_TYPE_WARNING,
				$warningTs // it's ok to pass ts since there is a DATE(FROM_UNIX.. under the hood
			);

			// check if there are more recent warnings successfully sent
			// because we don't want to send old warnings when there is a newer one
			// was sent successfully
			if ( count(
				BlockInactiveMailRecord::getMoreRecent(
					$user->getId(),
					BlockInactiveMailRecord::MAIL_TYPE_WARNING,
					$warningTs
				) )
			) {
				continue;
			}

			if ( count( $warningsSent ) ) {
				continue;
			}

			$warningsMissed[] = [
				'day' => $dayLeft,
				'ts' => $warningTs
			];

		}
		return $warningsMissed;
	}

}
