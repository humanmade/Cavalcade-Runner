<?php

namespace HM\Cavalcade\Runner;

use Exception;
use PDO;
use PDOException;

const SCHEMA_VERSION = 12;

const EMPTY_DELETED_AT = '9999-12-31 23:59:59';

class DBSchema
{
    private $log;
    private $db;
    private $table;
    private $charset;
    private $collate;
    private $schema_version;

    public function __construct($log, $db, $table_prefix, $charset, $collate, $schema_version)
    {
        $this->log = $log;
        $this->db = $db;
        $this->table = $table_prefix . 'cavalcade_jobs';
        $this->charset = $charset;
        $this->collate = $collate;
        $this->schema_version = $schema_version;
    }

    protected function execute_query($query, $func)
    {
        try {
            $stmt = $this->db->prepare($query);
            return $func($stmt);
        } catch (PDOException $e) {
            $err = $e->errorInfo;

            ob_start();
            $stmt->debugDumpParams();
            $dump = ob_get_contents();
            ob_end_clean();

            $this->log->error('database error', [
                'dump' => $dump,
                'code' => $err[0],
                'driver_code' => $err[1],
                'error_message' => $err[2],
            ]);

            throw new Exception('database error', 0, $e);
        }
    }

    public function create_or_upgrade()
    {
        if ($this->schema_version === null) {
            return $this->create_table();
        }

        if ($this->schema_version === SCHEMA_VERSION) {
            $this->log->debug('db upgrade not required');
            return null;
        }

        try {
            $this->lock_table();
            switch ($this->schema_version) {
                case 2:
                    $this->upgrade_database_to_3();
                case 3:
                    $this->upgrade_database_to_4();
                case 4:
                    $this->upgrade_database_to_5();
                case 5:
                    $this->upgrade_database_to_6();
                case 6:
                    $this->upgrade_database_to_7();
                case 7:
                case 8:
                    $this->upgrade_database_to_9();
                case 9:
                    $this->upgrade_database_to_10();
                case 10:
                    $this->upgrade_database_to_11();
                case 11:
                    $this->upgrade_database_to_12();
                    break;
                case 1:
                    $this->log->fatal('update from database version 1 is no longer supported');
                    throw new Exception('unsupported schema version');
                    break;
                default:
                    $this->log->fatal(
                        'unknown schema version',
                        ['schema_version' => $this->schema_version]
                    );
                    throw new Exception('unsupported schema version');
            }
        } finally {
            $this->unlock_table();
        }

        $this->log->info('db schema upgraded', ['schema_version' => SCHEMA_VERSION]);

        $this->schema_version = SCHEMA_VERSION;

        return $this->schema_version;
    }

    private function lock_table()
    {
        $this->execute_query("LOCK TABLES `$this->table` WRITE", function ($stmt) {
            $stmt->execute();
        });
    }

    private function unlock_table()
    {
        $this->execute_query('UNLOCK TABLES', function ($stmt) {
            $stmt->execute();
        });
    }

    public function create_table()
    {
        $empty_deleted_at = EMPTY_DELETED_AT;
        $query = "CREATE TABLE `$this->table` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,

            `site` bigint(20) unsigned NOT NULL,
            `hook` varchar(255) NOT NULL,
            `hook_instance` varchar(255) NOT NULL DEFAULT '',
            `args` longtext NOT NULL,
            `args_digest` char(64) NOT NULL,

            `nextrun` datetime NOT NULL,
            `interval` int unsigned DEFAULT NULL,
            `status` enum('waiting','running','done') NOT NULL DEFAULT 'waiting',
            `schedule` varchar(255) DEFAULT NULL,
            `registered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `revised_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `started_at` datetime DEFAULT NULL,
            `finished_at` datetime DEFAULT NULL,
            `deleted_at` datetime NOT NULL DEFAULT '$empty_deleted_at',

            PRIMARY KEY (`id`),
            UNIQUE KEY `uniqueness` (`site`, `hook`, `hook_instance`, `args_digest`, `deleted_at`),
            KEY `status` (`status`, `deleted_at`),
            KEY `status-finished_at` (`status`, `finished_at`),
            KEY `site` (`site`, `deleted_at`),
            KEY `hook` (`hook`, `deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET $this->charset COLLATE $this->collate";

        $this->execute_query($query, function ($stmt) {
            $stmt->execute();
            $this->log->debug('table creation executed');
        });

        $this->log->info('db table created', ['table' => $this->table]);

        $this->schema_version = SCHEMA_VERSION;

        return $this->schema_version;
    }

    /**
     * Upgrade Cavalcade database tables to version 3.
     *
     * Add indexes required for pre-flight filters.
     */
    private function upgrade_database_to_3()
    {
        $this->execute_query(
            "ALTER TABLE `$this->table` ADD INDEX `site` (`site`), ADD INDEX `hook` (`hook`)",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->log->debug('db upgraded to schema version 3');
    }

    /**
     * Upgrade Cavalcade database tables to version 4.
     *
     * Remove nextrun index as it negatively affects performance.
     */
    private function upgrade_database_to_4()
    {
        $this->execute_query(
            "ALTER TABLE `$this->table` DROP INDEX `nextrun`",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->log->debug('db upgraded to schema version 4');
    }

    /**
     * Upgrade Cavalcade database tables to version 5.
     *
     * Add `deleted_at` column in the jobs table.
     */
    private function upgrade_database_to_5()
    {
        $this->execute_query(
            "ALTER TABLE `$this->table`
            DROP INDEX `status`,
            DROP INDEX `site`,
            DROP INDEX `hook`,
            ADD `deleted_at` datetime DEFAULT NULL,
            ADD INDEX `status` (`status`, `deleted_at`),
            ADD INDEX `site` (`site`, `deleted_at`),
            ADD INDEX `hook` (`hook`, `deleted_at`)",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->log->debug('db upgraded to schema version 5');
    }

    /**
     * Upgrade Cavalcade database tables to version 6.
     *
     * Add `finished_at` column in the jobs table.
     */
    private function upgrade_database_to_6()
    {
        $this->execute_query(
            "ALTER TABLE `$this->table`
            ADD `finished_at` datetime DEFAULT NULL AFTER `schedule`,
            ADD INDEX `status-finished_at` (`status`, `finished_at`)",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->log->debug('db upgraded to schema version 6');
    }

    /**
     * Upgrade Cavalcade database tables to version 7.
     *
     * Add lifecycle timestamps.
     */
    private function upgrade_database_to_7()
    {
        $this->execute_query(
            "ALTER TABLE `$this->table`
            DROP `start`,
            ADD `started_at` datetime DEFAULT NULL AFTER `schedule`,
            ADD `revised_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `schedule`,
            ADD `registered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `schedule`",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->log->debug('db upgraded to schema version 7');
    }

    /**
     * Upgrade Cavalcade database tables to version 9.
     *
     * Drop unused table.
     */
    private function upgrade_database_to_9()
    {
        $this->execute_query(
            "DROP TABLE IF EXISTS `$this->table`",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->log->debug('db upgraded to schema version 9');
    }

    /**
     * Upgrade Cavalcade database tables to version 10.
     *
     * Delete old-formatted data.
     */
    private function upgrade_database_to_10()
    {
        $this->execute_query(
            "DELETE FROM `$this->table`
            WHERE `finished_at` is NULL AND status IN ('completed', 'failed')",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->execute_query(
            "UPDATE `$this->table`
            SET `status` = 'done'
            WHERE `status` IN ('completed', 'failed')",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->execute_query(
            "ALTER TABLE `$this->table`
            MODIFY `status` enum('waiting','running','done') NOT NULL DEFAULT 'waiting'",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->log->debug('db upgraded to schema version 10');
    }

    /**
     * Upgrade Cavalcade database tables to version 11.
     *
     * Apply unique constraint.
     */
    private function upgrade_database_to_11()
    {
        $this->execute_query(
            "DELETE FROM `$this->table` WHERE `deleted_at` IS NOT NULL",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->execute_query(
            "ALTER TABLE `$this->table`
            ADD `hook_instance` varchar(255) DEFAULT NULL AFTER `hook`,
            ADD `args_digest` char(64) AFTER `args`",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->execute_query(
            "UPDATE `$this->table` SET `hook_instance` = `nextrun` WHERE `interval` IS NULL",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->execute_query(
            "SELECT `id`, `args` FROM `$this->table`",
            function ($stmt) {
                $stmt->execute();
                while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
                    $this->execute_query(
                        "UPDATE `$this->table`
                        SET `args_digest` = :args_digest
                        WHERE `id` = :id",
                        function ($stmt) use ($row) {
                            $stmt->bindValue(':args_digest', hash('sha256', $row->args));
                            $stmt->bindValue(':id', $row->id, PDO::PARAM_INT);
                            $stmt->execute();
                        }
                    );
                }
            }
        );

        $this->execute_query(
            "SELECT MAX(`id`) as `maxid` FROM `$this->table`
            GROUP BY `site`, `hook`, `hook_instance`, `args_digest`",
            function ($stmt) {
                $stmt->execute();
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                if (count($ids) == 0) {
                    return;
                }

                $in_query = '(' . implode(',', array_fill(0, count($ids), '?')) . ')';

                $this->execute_query(
                    "DELETE FROM `$this->table` WHERE `id` NOT IN $in_query",
                    function ($stmt) use ($ids) {
                        $stmt->execute($ids);
                    }
                );
            }
        );

        $this->execute_query(
            "ALTER TABLE `$this->table` MODIFY `args_digest` char(64) NOT NULL",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->execute_query(
            "ALTER TABLE `$this->table`
            ADD UNIQUE KEY `uniqueness` (`site`, `hook`, `hook_instance`, `args_digest`)",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->log->debug('db upgraded to schema version 11');
    }

    /**
     * Upgrade Cavalcade database tables to version 12.
     *
     * Fix incorrect unique constraint.
     */
    private function upgrade_database_to_12()
    {
        $empty_deleted_at = EMPTY_DELETED_AT;

        $this->execute_query(
            "UPDATE `$this->table` SET `hook_instance` = '' WHERE `hook_instance` IS NULL",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->execute_query(
            "ALTER TABLE `$this->table` MODIFY `hook_instance` varchar(255) NOT NULL DEFAULT ''",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->execute_query(
            "UPDATE `$this->table`
            SET `deleted_at` = '$empty_deleted_at'
            WHERE `deleted_at` IS NULL",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->execute_query(
            "ALTER TABLE `$this->table`
            MODIFY `deleted_at` datetime NOT NULL DEFAULT '$empty_deleted_at',
            DROP INDEX `uniqueness`,
            ADD UNIQUE KEY `uniqueness` (`site`, `hook`, `hook_instance`, `args_digest`, `deleted_at`)",
            function ($stmt) {
                $stmt->execute();
            }
        );

        $this->log->debug('db upgraded to schema version 12');
    }
}
