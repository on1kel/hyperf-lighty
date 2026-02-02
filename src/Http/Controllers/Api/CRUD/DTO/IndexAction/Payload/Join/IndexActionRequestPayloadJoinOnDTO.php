<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Join;

use Khazhinov\PhpSupport\DTO\Custer\EnumCaster;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where\IndexActionRequestPayloadWhereOperatorEnum;
use Spatie\DataTransferObject\Attributes\CastWith;

class IndexActionRequestPayloadJoinOnDTO extends DataTransferObject
{
    /**
     * @var string
     */
    public string $left;

    /**
     * @var IndexActionRequestPayloadWhereOperatorEnum
     */
    #[CastWith(EnumCaster::class, enumType: IndexActionRequestPayloadWhereOperatorEnum::class)]
    public IndexActionRequestPayloadWhereOperatorEnum $operator = IndexActionRequestPayloadWhereOperatorEnum::Equal;

    /**
     * @var string
     */
    public string $right;
}
