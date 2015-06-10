<?php

// TODO: Remove this, and move it into the client side or static script

return "CREATE TABLE IF NOT EXISTS `{$wpdb->base_prefix}cavalcade_jobs` (
	`hook` varchar(255) NOT NULL,
	`start` datetime NOT NULL,
	`nextrun` datetime NOT NULL,
	`interval` int unsigned NOT NULL,
	`status` varchar(255) NOT NULL DEFAULT 'waiting',

	PRIMARY KEY (`hook`),
	KEY `status` (`status`)
) ENGINE=InnoDB
";
