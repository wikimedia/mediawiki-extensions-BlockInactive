<?php

namespace MediaWiki\Extension\BlockInactive;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\User\User;

class BlockInactiveHooks implements
	\MediaWiki\Hook\UserLoginCompleteHook,
	\MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook
{

	/**
	 * @param User $user
	 * @param string &$injectHtml
	 * @param bool $direct
	 */
	public function onUserLoginComplete( $user, &$injectHtml, $direct ) {
		// On a successful login update the `user_touched` in the database
		// since it is not being updated by default
		$user->checkAndSetTouched();
	}

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable(
			'blockinactive_emails',
			__DIR__ . '/../sql/add_blockinactive_emails.sql'
		);
	}

}
