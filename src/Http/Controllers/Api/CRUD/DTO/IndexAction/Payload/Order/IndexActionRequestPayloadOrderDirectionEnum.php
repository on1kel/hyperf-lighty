<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Order;

enum IndexActionRequestPayloadOrderDirectionEnum: string
{
    case ASC = 'asc';
    case DESC = 'desc';
}
