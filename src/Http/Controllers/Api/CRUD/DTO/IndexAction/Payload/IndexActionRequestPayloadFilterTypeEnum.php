<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload;

enum IndexActionRequestPayloadFilterTypeEnum: string
{
    case Group = 'group';
    case Single = 'single';
}
