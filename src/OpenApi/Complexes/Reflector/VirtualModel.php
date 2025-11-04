<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\Reflector;

use ArrayAccess;
use JsonSerializable;

/**
 * Лёгкая «виртуальная модель» под hyperf/resource:
 * - хранит атрибуты/отношения в массивах;
 * - relationLoaded() → true для помеченных связей;
 * - getRelation()/setRelation() доступны, как у Eloquent;
 * - прокидывает вызовы к $source (если передан реальный объект).
 */
final class VirtualModel implements ArrayAccess, JsonSerializable
{
    private ?object $source;

    /** @var array<string,mixed> */
    private array $attributes = [];

    /** @var array<string,mixed> */
    private array $relations = [];

    /** @var array<string,bool> */
    private array $loaded = [];

    public function __construct(?object $source = null, array $attributes = [])
    {
        $this->source = $source;
        $this->fill($attributes);
    }

    /** @param array<string,mixed> $attrs */
    public function fill(array $attrs): self
    {
        foreach ($attrs as $k => $v) {
            $this->attributes[$k] = $v;
        }

        return $this;
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes) || array_key_exists($name, $this->relations);
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->source && method_exists($this->source, $name)) {
            return $this->source->{$name}(...$arguments);
        }

        return null;
    }

    /** Пометить связь как загруженную и установить значение. */
    public function setRelation(string $name, mixed $value): self
    {
        $this->relations[$name] = $value;
        $this->loaded[$name] = true;

        return $this;
    }

    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    public function relationLoaded(string $name): bool
    {
        return (bool)($this->loaded[$name] ?? false);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->attributes + $this->relations;
    }

    // ArrayAccess
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->{$offset});
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->{$offset};
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->{$offset} = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset], $this->relations[$offset], $this->loaded[$offset]);
    }

    // JsonSerializable
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
