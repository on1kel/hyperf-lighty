<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload;

use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Spatie\DataTransferObject\Attributes\CastWith;
use Spatie\DataTransferObject\Casters\ArrayCaster;

class IndexActionRequestPayloadExportDTO extends DataTransferObject
{
    /**
     * @var ?string
     */
    public ?string $file_name = null;

    /**
     * @var ?string
     */
    public ?string $export_type = null;

    /**
     * @var IndexActionRequestPayloadExportFieldItemDTO[]
     */
    #[CastWith(ArrayCaster::class, itemType: IndexActionRequestPayloadExportFieldItemDTO::class)]
    public array $fields = [];
}
