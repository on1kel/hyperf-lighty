<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Select;

enum IndexActionRequestPayloadAggregationsEnum: string
{
    case Count = 'count';
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';

    /**
     * @return array<string>
     */
    public static function getAllValues(): array
    {
        return array_map(
            static fn(self $case) => $case->value,
            self::cases()
        );
    }

    public static function tryFromSelect(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower($value);

        return self::tryFrom($value);
    }
}
