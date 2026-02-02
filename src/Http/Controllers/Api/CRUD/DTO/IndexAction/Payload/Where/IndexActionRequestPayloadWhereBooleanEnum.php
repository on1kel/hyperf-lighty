<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where;

enum IndexActionRequestPayloadWhereBooleanEnum: string
{
    case And = 'and';
    case Or = 'or';
}
