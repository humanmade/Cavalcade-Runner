<?php

namespace HM\Cavalcade\Runner;

use DateInterval;
use DateTime;
use DateTimeZone;
use PDO;

class Job
{
    public $id;
    public $site;
    public $hook;
    public $hook_instance;
    public $args;
    public $args_digest;
    public $nextrun;
    public $interval;
    public $status;
    public $schedule;
    public $registered_at;
    public $revised_at;
    public $started_at;
    public $finished_at;
    public $deleted_at;

    protected $db;
    protected $table_prefix;
    protected $table;

    public function __construct($db, $table_prefix)
    {
        $this->db = $db;
        $this->table = "{$table_prefix}cavalcade_jobs";
        $this->table_prefix = $table_prefix;
    }

    public function get_site_url()
    {
        $query = "SHOW TABLES LIKE '{$this->table_prefix}blogs'";
        $statement = $this->db->prepare($query);
        $statement->execute();

        if (0 === $statement->rowCount()) {
            return false;
        }

        $query = "SELECT `domain`, `path` FROM `$this->table`
                  WHERE `blog_id` = :site";

        $statement = $this->db->prepare($query);
        $statement->bindValue(':site', $this->site);
        $statement->execute();

        $data = $statement->fetch(PDO::FETCH_ASSOC);
        $url = $data['domain'] . $data['path'];
        return $url;
    }

    /**
     * Acquire a "running" lock on this job
     *
     * Ensures that only one supervisor can run the job at once.
     *
     * @return bool True if we acquired the lock, false if we couldn't.
     */
    public function acquire_lock()
    {
        $started_at = new DateTime('now', new DateTimeZone('UTC'));
        $this->started_at = $started_at->format(MYSQL_DATE_FORMAT);

        $query = "UPDATE `$this->table`
                  SET `status` = 'running', `started_at` = :started_at
                  WHERE `status` = 'waiting' AND id = :id";

        $statement = $this->db->prepare($query);
        $statement->bindValue(':id', $this->id);
        $statement->bindValue(':started_at', $this->started_at);
        $statement->execute();

        $rows = $statement->rowCount();
        return ($rows === 1);
    }

    public function mark_done()
    {
        $finished_at = new DateTime('now', new DateTimeZone('UTC'));
        $this->finished_at = $finished_at->format(MYSQL_DATE_FORMAT);

        if ($this->interval) {
            $this->reschedule();
        } else {
            $query = "UPDATE `$this->table`
                      SET `status` = 'done', `finished_at` = :finished_at
                      WHERE `id` = :id";

            $statement = $this->db->prepare($query);
            $statement->bindValue(':id', $this->id);
            $statement->bindValue(':finished_at', $this->finished_at);
            $statement->execute();
        }
    }

    public function reschedule()
    {
        $date = new DateTime($this->nextrun, new DateTimeZone('UTC'));
        $date->add(new DateInterval("PT{$this->interval}S"));
        $this->nextrun = $date->format(MYSQL_DATE_FORMAT);

        $this->status = 'waiting';

        $query = "UPDATE `$this->table`
                  SET `status` = :status, `nextrun` = :nextrun, `finished_at` = :finished_at
                  WHERE `id` = :id";

        $statement = $this->db->prepare($query);
        $statement->bindValue(':id', $this->id);
        $statement->bindValue(':status', $this->status);
        $statement->bindValue(':nextrun', $this->nextrun);
        $statement->bindValue(':finished_at', $this->finished_at);
        $statement->execute();
    }
}
