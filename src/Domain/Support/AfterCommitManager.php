<?php

namespace On1kel\HyperfLighty\Domain\Support;

final class AfterCommitManager
{
    /** @var array<callable> */
    private array $callbacks = [];

    public function run(callable $callback): void
    {
        $this->callbacks[] = $callback;
    }

    public function flush(): void
    {
        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $cb) {
            $cb();
        }
    }

    public function clear(): void
    {
        $this->callbacks = [];
    }

    public function isEmpty(): bool
    {
        return $this->callbacks === [];
    }
}
