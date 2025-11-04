<?php

namespace On1kel\HyperfLighty\Domain\Contracts;

interface HasQueue
{
    public function queue(): string;
}
