CREATE TABLE IF NOT EXISTS `0_mcp_oauth_client` (
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `kind` varchar(8) NOT NULL,
  `client_name` varchar(255) NOT NULL DEFAULT '',
  `redirect_uris` text NOT NULL,
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `fetched_at` datetime DEFAULT NULL,
  `fetch_ok_at` datetime DEFAULT NULL,
  `source_ip` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `0_mcp_oauth_code` (
  `code_hash` char(64) NOT NULL,
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `company` int(11) NOT NULL,
  `login` varchar(60) NOT NULL,
  `redirect_uri` text NOT NULL,
  `code_challenge` varchar(128) NOT NULL,
  `scope` varchar(8) NOT NULL,
  `resource` varchar(512) NOT NULL DEFAULT '',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `replayed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`code_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `0_mcp_oauth_refresh` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL,
  `family` char(32) NOT NULL,
  `client_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `company` int(11) NOT NULL,
  `login` varchar(60) NOT NULL,
  `scope` varchar(8) NOT NULL,
  `graphql_jti` char(32) NOT NULL,
  `expires_at` datetime NOT NULL,
  `rotated_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `family` (`family`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
