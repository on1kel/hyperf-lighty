<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\DestroyAction\Option;

use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BaseCRUDOptionDTO;

class DestroyActionOptionsDTO extends BaseCRUDOptionDTO
{
    public bool $force = false;
}
