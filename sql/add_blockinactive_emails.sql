--
-- Tables for the BlockInactive extension
--

-- Emails table
CREATE TABLE /*_*/blockinactive_emails
(
	-- User id
	ba_user_id      int unsigned NOT NULL,

	-- Timestamp of the email
	ba_sent_ts      int unsigned NOT NULL,

	-- Email address the email was sent to
	ba_sent_email   varbinary(255) NOT NULL,

	-- Email type (see BlockInactiveMailRecord constants)
	ba_mail_type	tinyint(1) NOT NULL,

	-- Email sending attempt (number), own counter for each mail type
	ba_sent_attempt int NOT NULL

) /*$wgDBTableOptions*/;

-- For querying of all records from a certain user
CREATE INDEX /*i*/blockinactive_emails_user_ts ON /*_*/blockinactive_emails (ba_user_id, ba_sent_ts);
