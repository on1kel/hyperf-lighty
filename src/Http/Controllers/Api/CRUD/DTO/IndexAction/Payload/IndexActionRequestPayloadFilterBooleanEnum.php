<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload;

enum IndexActionRequestPayloadFilterBooleanEnum: string
{
    case And = 'and';
    case Or = 'or';
}
