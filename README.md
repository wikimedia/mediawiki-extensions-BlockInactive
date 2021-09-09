# BlockInactive

The extension is intended to help wiki administrators to keep track of
inactive users, send warning emails to such users and then automatically
block their account if no action were taken in time.

It's possible to configure number of days since last login to be considered
as inactivity, number of days since last login to actually block a user account
and setup a schedule for warning messages to be sent prior to blocking.

# Requirements

* MediaWiki 1.35+

# Setup

* Clone the repository into your `extensions` folder
* Add `wfLoadExtension( 'BlockInactive' )` to the bottom of your `LocalSettings.php`
* Run `php maintenance/update.php --quick`
* Configure `cron` to run `BlockInactive/maintenance/blockinactive.php` script `@daily`
* Navigate to `Special:BlockInactive` to see details

# Configure

* `$wgBlockInactiveThreshold` (default: `210`) - Number of days since the last login to start considering user as inactive and begin sending reminders
* `$wgBlockInactiveDaysBlock` (default: `270`) - Number of days since the last login to actually block the user
* `$wgBlockInactiveWarningDaysLeft` (default: `30, 5`) - Schedule in form of days left before blocking to send warning emails on

# i18n

* `blockinactive-config-mail-subject` - Warning email subject
* `blockinactive-config-mail-body` - Warning email body
* `blockinactive-config-mail-block-body` - Post-block email subject
* `blockinactive-config-mail-block-body` - Post-block email body

# Permissions

* `blockinactive` - allows to view the `Special:BlockInactive` page, by default is granted to `sysop` and
`bureaucrat` groups.

# Groups

The extension adds a special `alwaysactive` right, users having this right will be fully ignored by the extension.
By default, the right is granted to the `sysop` group.
