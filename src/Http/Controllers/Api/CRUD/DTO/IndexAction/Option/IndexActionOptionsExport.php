<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option;

use Khazhinov\PhpSupport\DTO\Custer\EnumCaster;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use Khazhinov\PhpSupport\DTO\Validation\ClassExists;
use On1kel\HyperfLighty\Exports\ModelExport;
use Spatie\DataTransferObject\Attributes\CastWith;

class IndexActionOptionsExport extends DataTransferObject
{
    /**
     * @var bool
     */
    public bool $enable = true;

    /**
     * @var IndexActionOptionsExportExportTypeEnum
     */
    #[CastWith(EnumCaster::class, enumType: IndexActionOptionsExportExportTypeEnum::class)]
    public IndexActionOptionsExportExportTypeEnum $default_export_type = IndexActionOptionsExportExportTypeEnum::XLSX;

    /**
     * @var string
     */
    #[ClassExists]
    public string $exporter_class = ModelExport::class;
}
