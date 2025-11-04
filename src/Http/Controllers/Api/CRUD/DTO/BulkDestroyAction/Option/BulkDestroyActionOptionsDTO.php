<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BulkDestroyAction\Option;

use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BaseCRUDOptionDTO;

class BulkDestroyActionOptionsDTO extends BaseCRUDOptionDTO
{
    public bool $force = false;
}
