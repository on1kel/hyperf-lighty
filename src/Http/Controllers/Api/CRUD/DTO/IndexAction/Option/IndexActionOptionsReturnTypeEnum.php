<?php

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option;

enum IndexActionOptionsReturnTypeEnum: string
{
    case Resource = 'resource';
    case Export = 'export';
}
