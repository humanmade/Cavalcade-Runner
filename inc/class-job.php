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
    protected $log;

    public function __construct($db, $table_prefix, $log)
    {
        $this->db = $db;
        $this->table = $table_prefix . 'cavalcade_jobs';
        $this->table_prefix = $table_prefix;
        $this->log = $log;
    }

    public function log_values_full()
    {
        return $this->log_values() + [
            'nextrun' => $this->nextrun,
            'interval' => $this->interval,
            'status' => $this->status,
            'schedule' => $this->schedule,
            'registered_at' => $this->registered_at,
            'revised_at' => $this->revised_at,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
        ];
    }

    public function log_values()
    {
        return [
            'job_id' => intval($this->id),
            'site' => intval($this->site),
            'hook' => $this->hook,
            'hook_instance' => $this->hook_instance,
            'args' => $this->args,
            'args_digest' => $this->args_digest,
            'deleted_at' => $this->deleted_at,
        ];
    }

    public function get_site_url()
    {
        $this->log->debug('getting site url', $this->log_values());

        $row_count = $this->db->prepare_query(
            "SHOW TABLES LIKE '{$this->table_prefix}blogs'",
            function ($stmt) {
                $stmt->execute();
                return $stmt->rowCount();
            },
            true,
        );

        if (0 === $row_count) {
            $this->log->debug('site url not found', $this->log_values());

            return false;
        }

        $res = $this->db->prepare_query(
            "SELECT `domain`, `path` FROM `{$this->table_prefix}blogs` WHERE `blog_id` = :site",
            function ($stmt) {
                $stmt->bindValue(':site', $this->site, PDO::PARAM_INT);
                $stmt->execute();

                $data = $stmt->fetch(PDO::FETCH_OBJ);
                return $data->domain . $data->path;
            },
            true,
        );

        $this->log->debug('site url', ['siteurl' => $res] + $this->log_values());

        return $res;
    }

    /**
     * Acquire a "running" lock on this this
     *
     * Ensures that only one supervisor can run the this at once.
     *
     * @return bool True if we acquired the lock, false if we couldn't.
     */
    public function acquire_lock()
    {
        $this->log->debug('acquiring lock', $this->log_values());

        $started_at = new DateTime('now', new DateTimeZone('UTC'));
        $this->started_at = $started_at->format(MYSQL_DATE_FORMAT);

        $res = $this->db->prepare_query(
            "UPDATE `$this->table`
             SET `status` = 'running', `started_at` = :started_at
             WHERE `status` = 'waiting' AND id = :id",
            function ($stmt) {
                $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
                $stmt->bindValue(':started_at', $this->started_at);
                $stmt->execute();

                return $stmt->rowCount() === 1;
            },
            true,
        );

        if ($res) {
            $this->log->debug('lock acquired', $this->log_values());
        } else {
            $this->log->debug('lock not acquired', $this->log_values());
        }

        return $res;
    }

    public function cancel_lock()
    {
        $this->log->debug('canceling lock', $this->log_values());

        $this->db->prepare_query(
            "UPDATE `$this->table`
             SET `status` = 'waiting', `started_at` = NULL
             WHERE id = :id",
            function ($stmt) {
                $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
                $stmt->execute();
            },
            true,
        );

        $this->log->debug('lock canceled', $this->log_values());
    }

    public function mark_done()
    {
        $this->log->debug('marking as done', $this->log_values());

        $finished_at = new DateTime('now', new DateTimeZone('UTC'));
        $this->finished_at = $finished_at->format(MYSQL_DATE_FORMAT);

        if ($this->interval) {
            $this->reschedule();
            $this->log->debug('rescheduled', $this->log_values());
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
                true,
            );

            $this->log->debug('marked as done', $this->log_values());
        }
    }

    public function mark_waiting()
    {
        $this->log->debug('marking as waiting', $this->log_values());

        $this->status = 'waiting';

        $this->db->prepare_query(
            "UPDATE `$this->table`
             SET `status` = :status
             WHERE `id` = :id",
            function ($stmt) {
                $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
                $stmt->bindValue(':status', $this->status);
                $stmt->execute();
            },
            true,
        );

        $this->log->debug('marked as waiting', $this->log_values());
    }

    private function reschedule()
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
            true,
        );
    }
}
