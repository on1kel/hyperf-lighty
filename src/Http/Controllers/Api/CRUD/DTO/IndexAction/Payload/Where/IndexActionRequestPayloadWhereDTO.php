<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where;

use Khazhinov\PhpSupport\DTO\Custer\EnumCaster;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Spatie\DataTransferObject\Attributes\CastWith;
use Spatie\DataTransferObject\Casters\ArrayCaster;

class IndexActionRequestPayloadWhereDTO extends DataTransferObject
{
    /**
     * @var IndexActionRequestPayloadWhereTypeEnum
     */
    #[CastWith(EnumCaster::class, enumType: IndexActionRequestPayloadWhereTypeEnum::class)]
    public IndexActionRequestPayloadWhereTypeEnum $type = IndexActionRequestPayloadWhereTypeEnum::Single;

    /**
     * @var IndexActionRequestPayloadWhereDTO[]
     */
    #[CastWith(ArrayCaster::class, itemType: IndexActionRequestPayloadWhereDTO::class)]
    public array $group = [];

    /**
     * @var string|null
     */
    public ?string $column = null;

    /**
     * @var IndexActionRequestPayloadWhereOperatorEnum
     */
    #[CastWith(EnumCaster::class, enumType: IndexActionRequestPayloadWhereOperatorEnum::class)]
    public IndexActionRequestPayloadWhereOperatorEnum $operator = IndexActionRequestPayloadWhereOperatorEnum::Equal;

    /**
     * @var IndexActionRequestPayloadWhereValueTypeEnum
     */
    #[CastWith(EnumCaster::class, enumType: IndexActionRequestPayloadWhereValueTypeEnum::class)]
    public IndexActionRequestPayloadWhereValueTypeEnum $value_type = IndexActionRequestPayloadWhereValueTypeEnum::Scalar;

    /**
     * @var mixed
     */
    public mixed $value = null;

    /**
     * @var IndexActionRequestPayloadWhereBooleanEnum
     */
    #[CastWith(EnumCaster::class, enumType: IndexActionRequestPayloadWhereBooleanEnum::class)]
    public IndexActionRequestPayloadWhereBooleanEnum $boolean = IndexActionRequestPayloadWhereBooleanEnum::And;
}
