<?php

namespace HM\Cavalcade\Runner;

use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use PDO;
use PDOException;

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
    protected $log;

    public function __construct($db, $table_prefix, $log)
    {
        $this->db = $db;
        $this->table = $table_prefix . 'cavalcade_jobs';
        $this->table_prefix = $table_prefix;
        $this->log = $log;
    }

    public static function log_values(Job $job)
    {
        return [
            'job_id' => intval($job->id),
            'hook' => $job->hook,
            'hook_instance' => $job->hook_instance,
            'args' => $job->args,
            'args_digest' => $job->args_digest,
            'nextrun' => $job->nextrun,
            'interval' => $job->interval,
            'status' => $job->status,
            'schedule' => $job->schedule,
            'registered_at' => $job->registered_at,
            'revised_at' => $job->revised_at,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'deleted_at' => $job->deleted_at,
        ];
    }

    public function get_site_url()
    {
        $row_count = $this->db->prepare_query(
            "SHOW TABLES LIKE '{$this->table_prefix}blogs'",
            function ($stmt) {
                $stmt->execute();
                return $stmt->rowCount();
            },
        );

        if (0 === $row_count) {
            return false;
        }

        return $this->db->prepare_query(
            "SELECT `domain`, `path` FROM `{$this->table_prefix}blogs` WHERE `blog_id` = :site",
            function ($stmt) {
                $stmt->bindValue(':site', $this->site, PDO::PARAM_INT);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_OBJ);
                return $data->domain . $data->path;
            },
        );
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

        return $this->db->prepare_query(
            "UPDATE `$this->table`
             SET `status` = 'running', `started_at` = :started_at
             WHERE `status` = 'waiting' AND id = :id",
            function ($stmt) {
                $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
                $stmt->bindValue(':started_at', $this->started_at);
                $stmt->execute();

                return $stmt->rowCount() === 1;
            },
        );
    }

    public function cancel_lock()
    {
        $this->db->prepare_query(
            "UPDATE `$this->table`
             SET `status` = 'waiting', `started_at` = NULL
             WHERE id = :id",
            function ($stmt) {
                $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
                $stmt->execute();
            },
        );
    }

    public function mark_done()
    {
        $finished_at = new DateTime('now', new DateTimeZone('UTC'));
        $this->finished_at = $finished_at->format(MYSQL_DATE_FORMAT);

        if ($this->interval) {
            $this->reschedule();
        } else {
            $this->db->prepare_query(
                "UPDATE `$this->table`
                 SET `status` = 'done', `finished_at` = :finished_at
                 WHERE `id` = :id",
                function ($stmt) {
                    $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
                    $stmt->bindValue(':finished_at', $this->finished_at);
                    $stmt->execute();
                },
            );
        }
    }

    public function reschedule()
    {
        $date = new DateTime('now', new DateTimeZone('UTC'));
        $date->add(new DateInterval("PT{$this->interval}S"));
        $this->nextrun = $date->format(MYSQL_DATE_FORMAT);

        $this->status = 'waiting';

        $this->db->prepare_query(
            "UPDATE `$this->table`
             SET `status` = :status, `nextrun` = :nextrun, `finished_at` = :finished_at
             WHERE `id` = :id",
            function ($stmt) {
                $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
                $stmt->bindValue(':status', $this->status);
                $stmt->bindValue(':nextrun', $this->nextrun);
                $stmt->bindValue(':finished_at', $this->finished_at);
                $stmt->execute();
            },
        );
    }
}
