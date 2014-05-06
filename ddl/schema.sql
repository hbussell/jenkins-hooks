 CREATE TABLE builds (
  id int(11) NOT NULL AUTO_INCREMENT,
  requestUri varchar(500) COLLATE utf8_unicode_ci NOT NULL,
  postbackUri varchar(500) COLLATE utf8_unicode_ci NOT NULL,
  jobName varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  buildNumber int NOT NULL,
  created datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY job_build_url (jobName, postbackUri, buildNumber)
) ENGINE=InnoDB;
