<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload;

use function count;

use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Khazhinov\PhpSupport\DTO\Validation\ArrayOfScalar;
use Khazhinov\PhpSupport\DTO\Validation\NumberBetween;
use Khazhinov\PhpSupport\Enums\ScalarTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsReturnTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Join\IndexActionRequestPayloadJoinDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Order\IndexActionRequestPayloadOrderDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Select\IndexActionRequestColumnDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where\IndexActionRequestPayloadWhereDTO;
use Spatie\DataTransferObject\Attributes\CastWith;
use Spatie\DataTransferObject\Casters\ArrayCaster;

class IndexActionRequestPayloadDTO extends DataTransferObject
{
    /**
     * @var IndexActionRequestColumnDTO[]
     */
    #[CastWith(ArrayCaster::class, itemType: IndexActionRequestColumnDTO::class)]
    public array $select = [];

    /**
     * @var IndexActionRequestPayloadWhereDTO[]
     */
    #[CastWith(ArrayCaster::class, itemType: IndexActionRequestPayloadWhereDTO::class)]
    public array $where = [];

    /**
     * @var IndexActionRequestPayloadJoinDTO[]
     */
    #[CastWith(ArrayCaster::class, itemType: IndexActionRequestPayloadJoinDTO::class)]
    public array $join = [];

    /**
     * @var IndexActionRequestPayloadOrderDTO[]
     */
    #[CastWith(ArrayCaster::class, itemType: IndexActionRequestPayloadOrderDTO::class)]
    public array $order = [];

    /**
     * @var array<string>
     */
    #[ArrayOfScalar(ScalarTypeEnum::String, true)]
    public array $group_by = [];

    /**
     * @var int
     */
    public int $page = 1;

    #[NumberBetween(1, 300)]
    public int $limit = 10;

    /**
     * @var bool
     */
    public bool $paginate = true;

    /**
     * @var array<string, mixed>|null
     */
    public array|null $with = null;

    public string $return_type = 'resource';

    /**
     * @var IndexActionRequestPayloadExportDTO
     */
    public IndexActionRequestPayloadExportDTO $export;

    /**
     * Проверка, является ли запрос аналитическим (с агрегациями или GROUP BY)
     *
     * @return bool
     */
    public function isAnalyticalQuery(): bool
    {
        return $this->hasAggregations() || $this->hasGroupBy();
    }

    /**
     * Проверка наличия агрегаций в SELECT
     *
     * @return bool
     */
    public function hasAggregations(): bool
    {
        foreach ($this->select as $column) {
            if ($column->aggregation !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверка наличия GROUP BY
     *
     * @return bool
     */
    public function hasGroupBy(): bool
    {
        return count($this->group_by) > 0;
    }

    /**
     * @return array<string, string>
     */
    public function getExportColumns(): array
    {
        $result = [];
        foreach ($this->export->fields as $export_object) {
            $result[$export_object->column] = $export_object->alias;
        }

        return $result;
    }

    public function hasExportColumns(): bool
    {
        return (bool) count($this->export->fields);
    }

    public function getReturnType(): IndexActionOptionsReturnTypeEnum
    {
        return IndexActionOptionsReturnTypeEnum::from($this->return_type);
    }
}
