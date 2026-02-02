<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Order;

enum IndexActionRequestPayloadOrderNullPositionEnum: string
{
    case First = 'first';
    case Last = 'last';
}
