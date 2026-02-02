<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Join;

use Khazhinov\PhpSupport\DTO\Custer\DataTransferObjectCaster;
use Khazhinov\PhpSupport\DTO\Custer\EnumCaster;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where\IndexActionRequestPayloadWhereDTO;
use Spatie\DataTransferObject\Attributes\CastWith;
use Spatie\DataTransferObject\Casters\ArrayCaster;

class IndexActionRequestPayloadJoinDTO extends DataTransferObject
{
    /**
     * @var IndexActionRequestPayloadJoinTypeEnum
     */
    #[CastWith(EnumCaster::class, enumType: IndexActionRequestPayloadJoinTypeEnum::class)]
    public IndexActionRequestPayloadJoinTypeEnum $type = IndexActionRequestPayloadJoinTypeEnum::LEFT;

    /**
     * @var string
     */
    public string $table;

    /**
     * @var IndexActionRequestPayloadJoinOnDTO
     */
    #[CastWith(DataTransferObjectCaster::class, dto_class: IndexActionRequestPayloadJoinOnDTO::class)]
    public IndexActionRequestPayloadJoinOnDTO $on;

    /**
     * @var IndexActionRequestPayloadWhereDTO[]
     */
    #[CastWith(ArrayCaster::class, itemType: IndexActionRequestPayloadWhereDTO::class)]
    public array $where = [];
}
