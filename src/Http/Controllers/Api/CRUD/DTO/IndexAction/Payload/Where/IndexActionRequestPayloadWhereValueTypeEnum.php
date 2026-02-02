<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where;

enum IndexActionRequestPayloadWhereValueTypeEnum: string
{
    case Pointer = 'pointer';
    case Scalar = 'scalar';
}
