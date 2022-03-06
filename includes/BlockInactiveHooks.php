<?php

namespace MediaWiki\Extension\BlockInactive;

use DatabaseUpdater;
use User;

class BlockInactiveHooks {

	/**
	 * @param User &$user
	 * @param string &$injectHtml
	 * @param bool $direct
	 */
	public static function onUserLoginComplete( User &$user, string &$injectHtml, bool $direct ) {
		// On a successful login update the `user_touched` in the database
		// since it is not being updated by default
		$user->checkAndSetTouched();
	}

	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable(
			'blockinactive_emails',
			__DIR__ . '/../sql/add_blockinactive_emails.sql'
		);
	}

}
