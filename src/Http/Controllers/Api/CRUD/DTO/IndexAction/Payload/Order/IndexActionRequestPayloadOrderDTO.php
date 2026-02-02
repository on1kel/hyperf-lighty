<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Order;

use Khazhinov\PhpSupport\DTO\Custer\EnumCaster;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Spatie\DataTransferObject\Attributes\CastWith;

class IndexActionRequestPayloadOrderDTO extends DataTransferObject
{
    /**
     * @var string
     */
    public string $column = 'id';

    /**
     * @var IndexActionRequestPayloadOrderDirectionEnum
     */
    #[CastWith(EnumCaster::class, enumType: IndexActionRequestPayloadOrderDirectionEnum::class)]
    public IndexActionRequestPayloadOrderDirectionEnum $direction = IndexActionRequestPayloadOrderDirectionEnum::ASC;

    /**
     * @var IndexActionRequestPayloadOrderNullPositionEnum
     */
    #[CastWith(EnumCaster::class, enumType: IndexActionRequestPayloadOrderNullPositionEnum::class)]
    public IndexActionRequestPayloadOrderNullPositionEnum $null_position = IndexActionRequestPayloadOrderNullPositionEnum::First;
}
