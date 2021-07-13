<?php

namespace HM\Cavalcade\Runner;

use Exception;
use PDO;
use PDOException;

class DB
{
    protected $db;

    public function __construct($log)
    {
        $this->log = $log;
    }

    public function connect($charset, $host, $user, $password, $name)
    {
        // Check if we're passed a Unix socket (`:/tmp/socket` or `localhost:/tmp/socket`)
        if (preg_match('#^[^:]*:(/.+)$#', $host, $matches)) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $matches[1], $name, $charset);
        } else {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);
        }

        $this->db = new PDO($dsn, $user, $password);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->exec_query('SET time_zone = "+00:00"');
    }

    public function get_connection()
    {
        return $this->db;
    }

    public function prepare_query($query, $func)
    {
        try {
            $stmt = $this->db->prepare($query);
            try {
                return $func($stmt);
            } finally {
                $stmt->closeCursor();
            }
        } catch (PDOException $e) {
            $err = $e->errorInfo;

            ob_start();
            if (isset($stmt)) {
                $stmt->debugDumpParams();
            }
            $dump = ob_get_contents();
            ob_end_clean();

            $this->log->error('database error', [
                'dump' => $dump,
                'err_content' => $err,
            ]);

            throw new Exception('database error', 0, $e);
        }
    }

    public function execute_query($query, $func = null)
    {
        try {
            $stmt = $this->db->query($query);
            try {
                if ($func) {
                    return $func($stmt);
                }
            } finally {
                $stmt->closeCursor();
            }
        } catch (PDOException $e) {
            $err = $e->errorInfo;

            ob_start();
            if (isset($stmt)) {
                $stmt->debugDumpParams();
            }
            $dump = ob_get_contents();
            ob_end_clean();

            $this->log->error('database error', [
                'dump' => $dump,
                'err_content' => $err,
            ]);

            throw new Exception('database error', 0, $e);
        }
    }

    public function exec_query($query)
    {
        try {
            return $this->db->exec($query);
        } catch (PDOException $e) {
            $this->log->error('database error', ['err_content' => $e->errorInfo]);

            throw new Exception('database error', 0, $e);
        }
    }
}
