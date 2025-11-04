<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\SetPositionAction;

use Hyperf\DbConnection\Model\Model;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Khazhinov\PhpSupport\DTO\Validation\ExistsInParents;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\SetPositionAction\Option\SetPositionActionOptionsDTO;

class SetPositionActionArgumentsDTO extends DataTransferObject
{
    public SetPositionActionOptionsDTO $options;

    #[ExistsInParents(parent: Model::class)]
    public string $model_class;
}
