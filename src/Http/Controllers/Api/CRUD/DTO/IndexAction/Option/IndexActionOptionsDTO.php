<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option;

use Khazhinov\PhpSupport\DTO\Custer\DataTransferObjectCaster;
use Khazhinov\PhpSupport\DTO\Validation\ClassExists;
use Khazhinov\PhpSupport\DTO\Validation\ExistsInParents;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BaseCRUDOptionDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\IndexActionRequestPayloadDTO;
use On1kel\HyperfLighty\Http\Resources\SingleResource;
use Spatie\DataTransferObject\Attributes\CastWith;

class IndexActionOptionsDTO extends BaseCRUDOptionDTO
{
    /**
     * @var IndexActionRequestActionConfigurationSelect
     */
    #[CastWith(DataTransferObjectCaster::class, dto_class: IndexActionRequestActionConfigurationSelect::class)]
    public IndexActionRequestActionConfigurationSelect $select;

    /**
     * @var IndexActionOptionsWhere
     */
    #[CastWith(DataTransferObjectCaster::class, dto_class: IndexActionOptionsWhere::class)]
    public IndexActionOptionsWhere $where;

    /**
     * @var IndexActionOptionsOrders
     */
    #[CastWith(DataTransferObjectCaster::class, dto_class: IndexActionOptionsOrders::class)]
    public IndexActionOptionsOrders $orders;

    /**
     * @var IndexActionRequestActionConfigurationJoin
     */
    #[CastWith(DataTransferObjectCaster::class, dto_class: IndexActionRequestActionConfigurationJoin::class)]
    public IndexActionRequestActionConfigurationJoin $join;

    /**
     * @var IndexActionRequestActionConfigurationGroupBy
     */
    #[CastWith(DataTransferObjectCaster::class, dto_class: IndexActionRequestActionConfigurationGroupBy::class)]
    public IndexActionRequestActionConfigurationGroupBy $group_by;

    /**
     * @var IndexActionOptionsPagination
     */
    #[CastWith(DataTransferObjectCaster::class, dto_class: IndexActionOptionsPagination::class)]
    public IndexActionOptionsPagination $pagination;

    //    /**
    //     * @var IndexActionOptionsExport
    //     */
    //    #[CastWith(DataTransferObjectCaster::class, dto_class: IndexActionOptionsExport::class)]
    //    public IndexActionOptionsExport $export;

    #[ClassExists(nullable: true)]
    #[ExistsInParents(parent: SingleResource::class, nullable: true)]
    public string|null $single_resource_class = null;

    /**
     * @param  IndexActionRequestPayloadDTO  $request
     * @return IndexActionOptionsReturnTypeEnum
     */
    public function getReturnTypeByRequestPayload(IndexActionRequestPayloadDTO $request): IndexActionOptionsReturnTypeEnum
    {
        //        if (! $this->export->enable) {
        //            return IndexActionOptionsReturnTypeEnum::Resource;
        //        }

        return $request->getReturnType();
    }
}
