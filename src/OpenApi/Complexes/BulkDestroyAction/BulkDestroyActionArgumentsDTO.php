<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\BulkDestroyAction;

use Hyperf\DbConnection\Model\Model;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Khazhinov\PhpSupport\DTO\Validation\ExistsInParents;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BulkDestroyAction\Option\BulkDestroyActionOptionsDTO;

class BulkDestroyActionArgumentsDTO extends DataTransferObject
{
    public BulkDestroyActionOptionsDTO $options;

    #[ExistsInParents(parent: Model::class)]
    public string $model_class;
}
