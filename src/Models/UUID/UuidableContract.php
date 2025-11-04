<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Models\UUID;

interface UuidableContract
{
    public function generateUuid(): string;

    public function getIncrementing(): bool;

    public function getKeyType(): string;
}
