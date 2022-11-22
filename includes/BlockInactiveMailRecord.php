<?php

namespace MediaWiki\Extension\BlockInactive;

use stdClass;

class BlockInactiveMailRecord {

	/** @var int */
	protected $userId;

	/**
	 * UNIX timestamp of the record
	 * @var int
	 */
	protected $sentTs;

	/** @var string */
	protected $sentEmail;

	/** @var int */
	protected $sentAttempt;

	/** @var int */
	protected $type;

	/**
	 * Email warns a user about upcoming block
	 */
	public const MAIL_TYPE_WARNING = 0;

	/**
	 * Emails notifies a user about the block and
	 * instructs how to remove the block
	 */
	public const MAIL_TYPE_BLOCK = 1;

	/**
	 * @param stdClass $row
	 *
	 * @return BlockInactiveMailRecord
	 */
	public static function newFromRow( stdClass $row ): BlockInactiveMailRecord {
		return new self(
			$row->ba_user_id,
			$row->ba_sent_ts,
			$row->ba_sent_email,
			$row->ba_sent_attempt,
			$row->ba_mail_type
		);
	}

	/**
	 * @param int $userId
	 * @param int|null $type
	 * @param int $ts
	 *
	 * @return BlockInactiveMailRecord[]
	 */
	public static function getMoreRecent( int $userId, ?int $type, int $ts ) {
		$dbr = wfGetDB( DB_REPLICA );
		$query = self::getQuery(
			$userId,
			0,
			$type,
			0
		);
		$query['conds'][0] = 'DATE(FROM_UNIXTIME(ba_sent_ts)) > DATE(FROM_UNIXTIME('
			. $dbr->addQuotes( $ts )
			. '))';
		$res = $dbr->select(
			$query['tables'],
			$query['vars'],
			$query['conds'],
			__METHOD__,
			$query['options']
		);
		$records = [];
		foreach ( $res as $row ) {
			$records[] = self::newFromRow( $row );
		}
		return $records;
	}

	/**
	 * @param int $userId
	 * @param int $limit
	 * @param int|null $type
	 * @param int|null $ts UNIX timestamp
	 *
	 * @return BlockInactiveMailRecord[]
	 */
	public static function findForUserId( int $userId, int $limit = 0, int $type = null, int $ts = null ): array {
		$dbr = wfGetDB( DB_REPLICA );
		$query = self::getQuery(
			$userId,
			$limit,
			$type,
			$ts
		);
		$res = $dbr->select(
			$query['tables'],
			$query['vars'],
			$query['conds'],
			__METHOD__,
			$query['options']
		);
		$records = [];
		foreach ( $res as $row ) {
			$records[] = self::newFromRow( $row );
		}
		return $records;
	}

	/**
	 * Inserts the mail record into the database
	 * automatically increments the sendAttempt field
	 * taking the mail type into account
	 *
	 * @param int $userId
	 * @param string $sentEmail
	 * @param int $mailType
	 * @param int|null $ts UNIX timestamp
	 */
	public static function insert(
		int $userId,
		string $sentEmail,
		int $mailType,
		int $ts = null
	) {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectField(
			'blockinactive_emails',
			'MAX(ba_sent_attempt) as ba_sent_attempt',
			[
				'ba_user_id' => $userId,
				'ba_mail_type' => $mailType
			],
			__METHOD__
		);
		if ( $row ) {
			$sentAttempt = (int)$row + 1;
		} else {
			$sentAttempt = 1;
		}
		$dbw->insert(
			'blockinactive_emails',
			[
				'ba_user_id' => $userId,
				'ba_sent_ts' => $ts ? $ts : time(),
				'ba_sent_email' => $sentEmail,
				'ba_sent_attempt' => $sentAttempt,
				'ba_mail_type' => $mailType
			],
			__METHOD__
		);
	}

	/**
	 * @param int $userId
	 * @param int $sentTs
	 * @param string $sentEmail
	 * @param int $sentAttempt
	 * @param int $mailType
	 */
	public function __construct(
		int $userId,
		int $sentTs,
		string $sentEmail,
		int $sentAttempt,
		int $mailType
	) {
		$this->userId = $userId;
		$this->sentTs = $sentTs;
		$this->sentEmail = $sentEmail;
		$this->sentAttempt = $sentAttempt;
		$this->type = $mailType;
	}

	/**
	 * @return int
	 */
	public function getUserId(): int {
		return $this->userId;
	}

	/**
	 * @return int
	 */
	public function getSentTs(): int {
		return $this->sentTs;
	}

	/**
	 * @return string
	 */
	public function getSentEmail(): string {
		return $this->sentEmail;
	}

	/**
	 * @return int
	 */
	public function getSentAttempt(): int {
		return $this->sentAttempt;
	}

	/**
	 * @return int
	 */
	public function getType(): int {
		return $this->type;
	}

	/**
	 * @param int $userId
	 * @param int $limit
	 * @param int|null $type
	 * @param int|null $ts
	 *
	 * @return array
	 */
	private static function getQuery( int $userId, int $limit = 0, int $type = null, int $ts = null ) {
		$dbr = wfGetDB( DB_REPLICA );
		$conds = [
			'ba_user_id' => $userId
		];
		if ( $type !== null ) {
			$conds['ba_mail_type'] = $type;
		}
		if ( $ts !== null ) {
			$conds[] = 'DATE(FROM_UNIXTIME(ba_sent_ts)) = DATE(FROM_UNIXTIME(' . $dbr->addQuotes( $ts ) . '))';
		}
		$options = [
			'ORDER BY' => 'ba_sent_ts DESC'
		];
		if ( $limit !== 0 ) {
			$options['LIMIT'] = $limit;
		}
		return [
			'tables' => 'blockinactive_emails',
			'vars' => '*',
			'conds' => $conds,
			'options' => $options
		];
	}

}
