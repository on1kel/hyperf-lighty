<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\UpdateAction;

use Hyperf\DbConnection\Model\Model;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Khazhinov\PhpSupport\DTO\Validation\ExistsInParents;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\UpdateAction\Option\UpdateActionOptionsDTO;
use On1kel\HyperfLighty\Http\Requests\BaseRequest;
use On1kel\HyperfLighty\Http\Resources\SingleResource;

class UpdateActionArgumentsDTO extends DataTransferObject
{
    public UpdateActionOptionsDTO $options;

    #[ExistsInParents(parent: Model::class)]
    public string $model_class;

    #[ExistsInParents(parent: SingleResource::class)]
    public string $single_resource;

    #[ExistsInParents(parent: BaseRequest::class, nullable: true)]
    public ?string $validation_request = null;
}
