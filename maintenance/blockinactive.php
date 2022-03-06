<?php

namespace MediaWiki\Extension\BlockInactive;

use Maintenance;
use Status;
use User;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
} else {
	require_once __DIR__ . '/../../../maintenance/Maintenance.php';
}

class BlockInactiveMaintenance extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'BlockInactive' );
		$this->addDescription( 'Runs BlockInactive extension jobs' );
		$this->addOption( 'dry', 'Runs a dry-run operations, no mails will be sent, no blocks will happen' );
		$this->addOption( 'noblock', 'No blocks will actually happen, mails will be sent' );
		$this->addOption( 'filter', 'Only do actions on the given usernames', false, true );
	}

	public function execute() {
		$dry = $this->hasOption( 'dry' );
		$noblock = $this->hasOption( 'noblock' );
		$filter = $this->getOption( 'filter' );
		$filterUsersAr = [];
		$this->output( "Running at " . date( 'F j, Y, g:i a', time() ) );

		if ( $dry ) {
			$this->output( "\n\t- Running DRY mode" );
		}

		if ( $noblock ) {
			$this->output( "\n\t- Running NOBLOCK mode" );
		}

		if ( $filter ) {
			$this->output( "\n\t- Filtering by usernames: " . $filter );
			$filterUsersAr = explode( ",", $filter );
		}

		$warningScheduleDaysLeft = $this->getConfig()->get( 'BlockInactiveWarningDaysLeft' );

		// Find all inactive users
		$inactiveUsers = BlockInactive::getInstance()->getInactiveUsers();

		// Print intro and config values
		$this->output( "\nLooking for users inactive for >= "
			. BlockInactive::getInstance()->getThreshold() / 60 / 60 / 24 . " days" );
		$this->output( "\nBlocking users inactive for >= "
			. BlockInactive::getInstance()->getBlockTime() / 60 / 60 / 24 . " days" );
		$this->output( "\nSending warnings on the following schedule: "
			. " [ "
			. implode( ', ', $warningScheduleDaysLeft )
			. " ] days " );
		$this->output( "\n------\n" );

		// Iterate inactive users
		foreach ( $inactiveUsers as $inactiveUser ) {

			if ( $filter ) {
				if ( !in_array( $inactiveUser->getName(), $filterUsersAr ) ) {
					continue;
				}
			}

			$daysLeftUntilBlock = BlockInactive::getInstance()->daysLeft( $inactiveUser );
			$futureBlockTime = BlockInactive::getInstance()->getUserBlockTime( $inactiveUser );

			$this->output( "\n" . $inactiveUser->getName() . " [ " . $inactiveUser->getId() . " ] " );
			$this->output( "\n\tInactive since: "
				. date(
					'F j, Y, g:i a',
					wfTimestamp( TS_UNIX, $inactiveUser->getDBTouched() )
				)
			);
			$this->output( "\n\tDays left until block: "
						   . $daysLeftUntilBlock
						   . " [ "
						   . date( 'F j, Y, g:i a', $futureBlockTime )
						   . " ]"
			);

			if ( $daysLeftUntilBlock <= 0 ) {
				$this->output( "\n\tBLOCKING THE USER.." );
				$this->output( "\n\tSending blocking email to " . $inactiveUser->getEmail() );
				if ( !$dry ) {
					$this->sendBlockEmail( $inactiveUser );
					if ( !$noblock ) {
						BlockInactive::getInstance()->blockUser( $inactiveUser );
					}
				}
				$this->output( "\n\tDone!" );
				continue;
			}

			// Nothing to be done below if the user has no email set
			if ( !$inactiveUser->getEmail() ) {
				$this->output( "\n\tThe user has no email set, skipped" );
				continue;
			}

			// Check if by chance user has any missed warnings due to the script
			// not ran at some point
			$missedWarnings = BlockInactive::getInstance()->getWarningsMissed( $inactiveUser );
			if ( count( $missedWarnings ) ) {
				// There are missed warnings, pick the most recent missing one
				$days = array_column( $missedWarnings, 'day' );
				$min = min( $days );
				$recentMissing = $missedWarnings[ array_search( $min, $days ) ];
				// We're done with any other notifications for this user this time
				// Send email with fake TS for mail record
				$this->output( "\n\tSending delayed ({$recentMissing['day']}) warning email to "
					. $inactiveUser->getEmail() );
				if ( !$dry ) {
					$status = $this->sendWarningEmail( $inactiveUser, $recentMissing['ts'] );
					// Status check
					if ( $status->isGood() ) {
						$this->output( "\n\tSMTP OK" );
					} else {
						$this->output( "\n\tSMTP FAIL: " . $status->getMessage() );
					}
				}
				continue;
			}

			// Check if we need to send the email (N days in advance)
			// accordingly to the schedule
			if (
				// TODO: this might be improved
				BlockInactive::getInstance()->matchesSchedule( $inactiveUser )
			) {
				$this->output( "\n\tSending warning email to " . $inactiveUser->getEmail() );
				if ( !$dry ) {
					$status = $this->sendWarningEmail( $inactiveUser );
					// Status check
					if ( $status->isGood() ) {
						$this->output( "\n\tSMTP OK" );
					} else {
						$this->output( "\n\tSMTP FAIL: " . $status->getMessage() );
					}
				}
			}
		}

		$this->output( "\nDone!" );
	}

	/**
	 * @param User $user
	 * @param null $ts
	 *
	 * @return Status
	 */
	private function sendWarningEmail( User $user, $ts = null ): Status {
		$status = $user->sendMail(
			wfMessage( 'blockinactive-config-mail-subject' )->text(),
			wfMessage( 'blockinactive-config-mail-body' )->text()
		);
		// Add record to the database
		BlockInactiveMailRecord::insert(
			$user->getId(),
			$user->getEmail(),
			BlockInactiveMailRecord::MAIL_TYPE_WARNING,
			$ts
		);
		return $status;
	}

	/**
	 * @param User $user
	 *
	 * @return Status
	 */
	private function sendBlockEmail( User $user ): Status {
		$status = $user->sendMail(
			wfMessage( 'blockinactive-config-mail-block-subject' )->text(),
			wfMessage( 'blockinactive-config-mail-block-body' )->text()
		);
		// Add record to the database
		BlockInactiveMailRecord::insert(
			$user->getId(),
			$user->getEmail(),
			BlockInactiveMailRecord::MAIL_TYPE_BLOCK
		);
		return $status;
	}

}

$maintClass = BlockInactiveMaintenance::class;
require_once RUN_MAINTENANCE_IF_MAIN;
