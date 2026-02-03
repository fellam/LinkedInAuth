CREATE TABLE /*_*/linkedinauth_tokens (
  lat_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lat_sub VARBINARY(128) NOT NULL,
  lat_access_token BLOB NOT NULL,
  lat_expires_at INT UNSIGNED NOT NULL,
  lat_updated_at DATETIME NOT NULL,
  lat_user_id INT UNSIGNED NULL,
  lat_username VARBINARY(255) NULL,
  PRIMARY KEY (lat_id),
  UNIQUE KEY lat_sub (lat_sub),
  KEY lat_user_id (lat_user_id)
) ENGINE=InnoDB, DEFAULT CHARSET=binary;
