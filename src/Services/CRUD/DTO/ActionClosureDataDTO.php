<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD\DTO;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as DatabaseBuilder;
use Khazhinov\PhpSupport\DTO\Custer\EnumCaster;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionClosureModeEnum;
use On1kel\HyperfLighty\Models\Model;
use Spatie\DataTransferObject\Attributes\CastWith;
use Throwable;

class ActionClosureDataDTO extends DataTransferObject
{
    #[CastWith(EnumCaster::class, enumType: ActionClosureModeEnum::class)]
    public ActionClosureModeEnum $mode;

    /**
     * @var Builder|DatabaseBuilder|Builder[]|Collection|\Illuminate\Support\Collection|array<mixed>|Model|\On1kel\HyperfLightyMongoDBBundle\Models\Model
     */
    public mixed $data;

    public ?Throwable $exception = null;

    public function hasException(): bool
    {
        return ! is_null($this->exception);
    }
}
