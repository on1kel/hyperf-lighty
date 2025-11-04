<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload;

use Khazhinov\PhpSupport\DTO\DataTransferObject;

class IndexActionRequestPayloadExportFieldItemDTO extends DataTransferObject
{
    /**
     * @var string
     */
    public string $column;

    /**
     * @var string
     */
    public string $alias;
}
