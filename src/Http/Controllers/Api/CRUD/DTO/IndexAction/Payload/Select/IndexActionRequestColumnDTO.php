<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Select;

use Khazhinov\PhpSupport\DTO\Custer\EnumCaster;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Spatie\DataTransferObject\Attributes\CastWith;

class IndexActionRequestColumnDTO extends DataTransferObject
{
    /**
     * @var string
     */
    public string $column;

    /**
     * @var IndexActionRequestPayloadAggregationsEnum|null
     */
    #[CastWith(EnumCaster::class, enumType: IndexActionRequestPayloadAggregationsEnum::class)]
    public ?IndexActionRequestPayloadAggregationsEnum $aggregation = null;

    /**
     * @var string|null
     */
    public ?string $alias = null;
}
