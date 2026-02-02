<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option;

use Khazhinov\PhpSupport\DTO\DataTransferObject;

class IndexActionRequestActionConfigurationSelect extends DataTransferObject
{
    /**
     * @var bool
     */
    public bool $enable = true;
}
