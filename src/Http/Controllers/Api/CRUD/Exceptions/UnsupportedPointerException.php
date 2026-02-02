<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\Exceptions;

use RuntimeException;
use Throwable;

class UnsupportedPointerException extends RuntimeException
{
    public function __construct(
        string $message = 'Unsupported pointer.',
        int $code = 400,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
