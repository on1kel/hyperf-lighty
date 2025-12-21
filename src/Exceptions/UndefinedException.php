<?php

namespace On1kel\HyperfLighty\Exceptions;

use RuntimeException;
use Throwable;

class UndefinedException extends RuntimeException
{
    public function __construct(string $message = "Something went wrong.", int $code = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}