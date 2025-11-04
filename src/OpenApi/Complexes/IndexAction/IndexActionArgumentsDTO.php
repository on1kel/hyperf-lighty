<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\IndexAction;

use Hyperf\DbConnection\Model\Model;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Khazhinov\PhpSupport\DTO\Validation\ExistsInParents;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsDTO;
use On1kel\HyperfLighty\Http\Resources\CollectionResource;

class IndexActionArgumentsDTO extends DataTransferObject
{
    public IndexActionOptionsDTO $options;

    /** @var class-string<Model> */
    #[ExistsInParents(parent: Model::class)]
    public string $model_class;

    #[ExistsInParents(parent: CollectionResource::class)]
    public string $collection_resource;
}
