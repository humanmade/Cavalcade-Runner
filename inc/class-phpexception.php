<?php

namespace HM\Cavalcade\Runner;

use RuntimeException;

class PHPException extends RuntimeException
{
    protected $errno;
    protected $errstr;

    public function __construct($message, $errno, $errstr, $errfile, $errline)
    {
        parent::__construct($message);

        $this->errno = $errno;
        $this->errstr = $errstr;
        $this->file = $errfile;
        $this->line = $errline;
    }

    public function getErrno()
    {
        return $this->errno;
    }

    public function getErrstr()
    {
        return $this->errstr;
    }
}
