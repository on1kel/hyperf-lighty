<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload;

enum IndexActionRequestPayloadFilterOperatorEnum: string
{
    case Equal = '=';
    case NotEqual = '!=';
    case Like = 'like';
    case ILike = 'ilike';
    case Gt = '>';
    case Gte = '>=';
    case Lt = '<';
    case Lte = '<=';
}
