<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD\Exceptions;

use RuntimeException;

class UnsupportedModelException extends RuntimeException
{
    public function __construct(string $current_class, string $base_class)
    {
        parent::__construct("Class $current_class must be inherited from class $base_class", 400);
    }
}
