<?php

namespace On1kel\HyperfLighty\Domain\Contracts;

interface Action
{
    public function handle(object $model): void;
}
