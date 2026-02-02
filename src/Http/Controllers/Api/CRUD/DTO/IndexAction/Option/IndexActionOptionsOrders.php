<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option;

use Khazhinov\PhpSupport\DTO\Custer\DataTransferObjectCaster;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Order\IndexActionRequestPayloadOrderDTO;
use Spatie\DataTransferObject\Attributes\CastWith;

class IndexActionOptionsOrders extends DataTransferObject
{
    /**
     * @var bool
     */
    public bool $enable = true;

    /**
     * @var IndexActionRequestPayloadOrderDTO|null
     */
    #[CastWith(DataTransferObjectCaster::class, dto_class: IndexActionRequestPayloadOrderDTO::class)]
    public ?IndexActionRequestPayloadOrderDTO $default_order = null;
}
