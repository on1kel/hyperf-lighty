<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\DestroyAction;

use Hyperf\DbConnection\Model\Model;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Khazhinov\PhpSupport\DTO\Validation\ExistsInParents;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\DestroyAction\Option\DestroyActionOptionsDTO;

class DestroyActionArgumentsDTO extends DataTransferObject
{
    public DestroyActionOptionsDTO $options;

    #[ExistsInParents(parent: Model::class)]
    public string $model_class;
}
