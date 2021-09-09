<?php

namespace MediaWiki\Extensions\BlockInactive;

use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;

/**
 * @group Database
 */
class BlockInactiveMaintenanceTest extends MaintenanceBaseTestCase {

	public function getMaintenanceClass() {
		return BlockInactiveMaintenance::class;
	}

	// TODO: ..

}
