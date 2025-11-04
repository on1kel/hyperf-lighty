<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\Reflector\DTO;

use Khazhinov\PhpSupport\DTO\Custer\EnumCaster;
use Khazhinov\PhpSupport\DTO\DataTransferObject;
use On1kel\HyperfLighty\OpenApi\Complexes\Reflector\SchemeTypeEnum;
use Spatie\DataTransferObject\Attributes\CastWith;

class ModelPropertyDTO extends DataTransferObject
{
    public string $name;
    public ?string $description = null;
    public ?string $related = null;
    public bool $nullable = false;

    /** Только для чтения (из @property-read) */
    public bool $read_only = false;

    /** Сырой PHP-тип из докблока ide-helper (например: "\Carbon\Carbon|null", "int|null") */
    public ?string $php_type = null;

    /** @var ModelPropertyDTO[] */
    public array $related_properties = [];

    public mixed $fake_value = null;

    #[CastWith(EnumCaster::class, enumType: SchemeTypeEnum::class)]
    public SchemeTypeEnum $type;

    public function withFakeValue(): self
    {
        if ($this->type === SchemeTypeEnum::Collection) {
            $item = [];
            foreach ($this->related_properties as $rp) {
                $item[$rp->name] = $rp->withFakeValue()->fake_value;
            }
            $this->fake_value = [$item];

            return $this;
        }

        if ($this->type === SchemeTypeEnum::Single) {
            $obj = [];
            foreach ($this->related_properties as $rp) {
                $obj[$rp->name] = $rp->withFakeValue()->fake_value;
            }
            $this->fake_value = $obj;

            return $this;
        }

        $this->fake_value = match ($this->type) {
            SchemeTypeEnum::Integer => 1,
            SchemeTypeEnum::Number => 1.0,
            SchemeTypeEnum::Boolean => true,
            default => 'sample',
        };

        return $this;
    }
}
