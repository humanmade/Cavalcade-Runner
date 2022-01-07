<?php

namespace HM\Cavalcade\Runner;

use Exception;
use PDO;
use PDOException;

class DB
{
    protected $db;
    protected $charset;
    protected $host;
    protected $user;
    protected $password;
    protected $name;
    protected $pdoclass;

    public function __construct($log, $pdoclass, $charset, $host, $user, $password, $name)
    {
        $this->log = $log;
        $this->pdoclass = $pdoclass;
        $this->charset = $charset;
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
        $this->name = $name;
    }

    public function connect()
    {
        // Check if we're passed a Unix socket (`:/tmp/socket` or `localhost:/tmp/socket`)
        if (preg_match('#^[^:]*:(/.+)$#', $this->host, $matches)) {
            $dsn = sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=%s',
                $matches[1],
                $this->name,
                $this->charset
            );
        } else {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $this->host,
                $this->name,
                $this->charset
            );
        }

        $this->db = new $this->pdoclass($dsn, $this->user, $this->password);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->exec_query('SET time_zone = "+00:00"');

        $this->log->debug('connected to db');
    }

    private function reconnect($error)
    {
        $this->log->info('retriable database error', [
            'err_content' => $error,
        ]);
        sleep(10);
        $this->connect();
    }

    public function get_connection()
    {
        return $this->db;
    }

    private function is_retriable_pdo_exception($e)
    {
        // $sqlstate_code = $e->errorInfo[0];
        $driver_code = $e->errorInfo[1];

        switch ($driver_code) {
            case 1927:
                // Connection was killed (MariaDB-specific)
            case 2006:
                // MySQL server has gone away
            case 2013:
                // Lost connection to MySQL server during query
                return true;
        }

        return false;
    }

    private function is_retriable_php_exception($e)
    {
        $errstr = $e->getErrstr();

        if (strpos($errstr, 'Packets out of order.') === 0) {
            return true;
        }

        return false;
    }

    public function prepare_query($query, $func, $allow_retry = false)
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
            if ($allow_retry && $this->is_retriable_pdo_exception($e)) {
                $this->reconnect($err);
                return $this->prepare_query($query, $func);
            }

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
        } catch (PHPException $e) {
            if ($allow_retry && $this->is_retriable_php_exception($e)) {
                $this->reconnect($e->getErrstr());
                return $this->prepare_query($query, $func);
            }

            throw $e;
        }
    }

    public function execute_query($query, $func = null, $allow_retry = false)
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
            if ($allow_retry && $this->is_retriable_pdo_exception($e)) {
                $this->reconnect($err);
                return $this->execute_query($query, $func);
            }

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
        } catch (PHPException $e) {
            if ($allow_retry && $this->is_retriable_php_exception($e)) {
                $this->reconnect($e->getErrstr());
                return $this->execute_query($query, $func);
            }

            throw $e;
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
