<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Transaction;

use Closure;

interface WithDBTransactionInterface
{
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollback(): void;

    public function transaction(Closure $closure): mixed;
}
