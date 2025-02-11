<?php

namespace MediaWiki\Extension\BlockInactive;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;

return [
	'BlockInactive.BlockInactive' => static function ( MediaWikiServices $services ): BlockInactive {
		return new BlockInactive(
			new ServiceOptions(
				BlockInactive::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getPermissionManager(),
			$services->getConnectionProvider(),
			$services->getUserFactory(),
			$services->getHookContainer(),
			$services->getDatabaseBlockStore()
		);
	},
];
