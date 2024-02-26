<?php

namespace HM\Cavalcade\Runner;

use RuntimeException;

class HealthcheckFailure extends RuntimeException
{
    private $type;
    private $data;

    public function __construct($type, $message, $data = [], $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->type = $type;
        $this->data = $data;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getData()
    {
        return $this->data;
    }
}
