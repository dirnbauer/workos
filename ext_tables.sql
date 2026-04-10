CREATE TABLE tx_workosauth_identity (
  uid int(11) NOT NULL auto_increment,
  pid int(11) DEFAULT '0' NOT NULL,
  tstamp int(11) DEFAULT '0' NOT NULL,
  crdate int(11) DEFAULT '0' NOT NULL,
  login_context varchar(8) DEFAULT '' NOT NULL,
  workos_user_id varchar(255) DEFAULT '' NOT NULL,
  email varchar(255) DEFAULT '' NOT NULL,
  user_table varchar(32) DEFAULT '' NOT NULL,
  user_uid int(11) DEFAULT '0' NOT NULL,
  PRIMARY KEY (uid),
  UNIQUE KEY workos_context_user (login_context, workos_user_id),
  KEY login_context_local_user (login_context, user_table, user_uid)
);
