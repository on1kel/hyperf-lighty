<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\ShowAction;

use Hyperf\DbConnection\Model\Model;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Khazhinov\PhpSupport\DTO\Validation\ExistsInParents;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ShowAction\Option\ShowActionOptionsDTO;
use On1kel\HyperfLighty\Http\Resources\SingleResource;

class ShowActionArgumentsDTO extends DataTransferObject
{
    public ShowActionOptionsDTO $options;

    #[ExistsInParents(parent: Model::class)]
    public string $model_class;

    #[ExistsInParents(parent: SingleResource::class)]
    public string $single_resource;
}
