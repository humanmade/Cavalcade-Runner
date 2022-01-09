<?php

namespace HM\Cavalcade\Runner;

use PDO;

class PDOTester extends PDO
{
    private static $next_error = null;
    private static $repeating_error = null;

    public function prepare($query, $options = [])
    {
        if (self::$next_error !== null) {
            $msg = self::$next_error;
            self::$next_error = null;
            trigger_error($msg);
            return false;
        }

        if (self::$repeating_error !== null) {
            trigger_error(self::$repeating_error);
            return false;
        }

        return parent::prepare($query, $options);
    }

    public static function set_next_error($message)
    {
        self::$next_error = $message;
    }

    public static function set_repeating_error($message)
    {
        self::$repeating_error = $message;
    }
}
