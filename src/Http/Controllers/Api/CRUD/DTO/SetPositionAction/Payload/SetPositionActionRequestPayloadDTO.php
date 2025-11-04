<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\SetPositionAction\Payload;

use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Khazhinov\PhpSupport\DTO\Validation\ArrayOfScalar;
use Khazhinov\PhpSupport\Enums\ScalarTypeEnum;

class SetPositionActionRequestPayloadDTO extends DataTransferObject
{
    /**
     * @var array<string>
     */
    #[ArrayOfScalar(ScalarTypeEnum::String)]
    public array $ids = [];
}
