<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\SetPositionAction\Option;

use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BaseCRUDOptionDTO;

class SetPositionActionOptionsDTO extends BaseCRUDOptionDTO
{
    public string $position_column = 'position';
}
