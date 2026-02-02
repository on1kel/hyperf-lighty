<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Join;

enum IndexActionRequestPayloadJoinTypeEnum: string
{
    case LEFT = 'left';
    case RIGHT = 'right';
    case FULL = 'full';
    case INNER = 'inner';
}
