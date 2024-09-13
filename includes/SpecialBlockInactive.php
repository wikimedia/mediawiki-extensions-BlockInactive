<?php

namespace MediaWiki\Extension\BlockInactive;

use MediaWiki\Html\Html;
use MediaWiki\Output\OutputPage;
use MediaWiki\SpecialPage\QueryPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MWException;
use Skin;
use stdClass;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;

class SpecialBlockInactive extends QueryPage {

	private UserFactory $userFactory;

	/**
	 * @inheritDoc
	 */
	public function __construct(
		UserFactory $userFactory
	) {
		parent::__construct( 'BlockInactive', 'blockinactive' );
		$this->userFactory = $userFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->getOutput()->addModules( 'ext.blockinactive' );
		return parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo() {
		return BlockInactive::getInstance()->getQuery(
			BlockInactive::getInstance()->getThreshold()
		);
	}

	/**
	 * @inheritDoc
	 */
	public function preprocessResults( $db, $res ) {
		if ( !$res->numRows() ) {
			return;
		}
	}

	/**
	 * This method isn't used, since we override outputResults, but
	 * we need to implement since abstract in parent class.
	 *
	 * @param Skin $skin
	 * @param stdClass $result Result row
	 *
	 * @return bool|string|void
	 * @throws MWException
	 */
	public function formatResult( $skin, $result ) {
		throw new MWException( "unimplemented" );
	}

	/**
	 * @inheritDoc
	 */
	protected function getOrderFields() {
		return [ 'u.user_touched' ];
	}

	/**
	 * @inheritDoc
	 */
	protected function sortDescending() {
		return false;
	}

	/**
	 * Output the results of the query.
	 *
	 * @param OutputPage $out
	 * @param Skin $skin (deprecated presumably)
	 * @param IDatabase $dbr
	 * @param IResultWrapper $res Results from query
	 * @param int $num Number of results
	 * @param int $offset Paging offset (Should always be 0 in our case)
	 */
	protected function outputResults( $out, $skin, $dbr, $res, $num, $offset ) {
		$this->getOutput()->addWikiMsg(
			'blockinactive-special-intro',
			$this->getConfig()->get( 'BlockInactiveThreshold' ),
			$this->getConfig()->get( 'BlockInactiveDaysBlock' ),
			implode( ', ', BlockInactive::getInstance()->getWarningSchedule() )
		);
		$this->outputTableStart();
		foreach ( $res as $row ) {
			$this->outputTableRow(
				$row
			);
		}
		$this->outputTableEnd();
	}

	/**
	 * Output the start of the table
	 *
	 * Including opening <table>, and first <tr> with column headers.
	 */
	protected function outputTableStart() {
		$out = $this->getOutput();
		$out->addModuleStyles( 'jquery.tablesorter.styles' );
		$out->addModules( 'jquery.tablesorter' );
		$out->addHTML(
			Html::openElement( 'table', [
				'class' => [
					'mw-blockinactive-table',
					'sortable',
					'wikitable'
				]
			] ) . Html::rawElement( 'thead', [], $this->getTableHeaderRow() ) . Html::openElement( 'tbody' )
		);
	}

	/**
	 * Get (not output) the header row for the table
	 *
	 * @return string The header row of the table
	 */
	protected function getTableHeaderRow() {
		$headers = [ 'username', 'userid', 'inactivetime', 'emailsent', 'actions' ];
		$ths = '';
		foreach ( $headers as $header ) {
			$ths .= Html::rawElement(
				'th',
				[],
				$this->msg( 'blockinactive-table-' . $header )->parse()
			);
		}
		return Html::rawElement( 'tr', [], $ths );
	}

	/**
	 * Output a row of the stats table
	 *
	 * @param stdClass $row
	 *
	 * @throws MWException
	 */
	protected function outputTableRow( stdClass $row ) {
		$linkRenderer = $this->getLinkRenderer();
		$user = $this->userFactory->newFromId( $row->user_id );
		$days = $this->daysSinceLogin( $user->getDBTouched() );
		$emailTs = $row->ba_sent_ts ? $row->ba_sent_ts : null;
		$row = Html::rawElement(
			'td',
			[],
			$linkRenderer->makeLink( $user->getUserPage(), $user->getName() )
		);
		$row .= Html::rawElement(
			'td',
			[],
			$user->getId()
		);
		$row .= Html::rawElement(
			'td',
			// Make sure js sorts it in numeric order
			[ 'data-sort-value' => $user->getDBTouched() ],
			$days . ' days'
		);
		$row .= Html::rawElement(
			'td',
			[],
			$emailTs
				? date( 'F j, Y, g:i a', wfTimestamp( TS_UNIX, $emailTs ) )
				: '-'
		);
		$row .= Html::rawElement(
			'td',
			[],
			$linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'Block', $user->getName() ),
				$this->msg( 'blockinactive-action-block' )->text()
			)
		);
		$this->getOutput()->addHTML( Html::rawElement( 'tr', [], $row ) );
	}

	/**
	 * Output closing </table>
	 */
	protected function outputTableEnd() {
		$this->getOutput()->addHTML(
			Html::closeElement( 'tbody' ) . Html::closeElement( 'table' )
		);
	}

	/**
	 * Converts MW_TS into number of days until now
	 * @param int $ts
	 *
	 * @return int
	 */
	protected function daysSinceLogin( int $ts ) {
		$unix = wfTimestamp( TS_UNIX, $ts );
		$diff = time() - $unix;
		$diffDays = floor( $diff / 60 / 60 / 24 );
		return (int)$diffDays;
	}

}
