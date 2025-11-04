<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Resources;

use Closure;
use Hyperf\Context\Context;
use Hyperf\Resource\Json\AnonymousResourceCollection;
use Hyperf\Resource\Json\JsonResource as BaseJsonResource;
use Hyperf\Resource\Value\MissingValue;
use Swow\Psr7\Message\ServerRequestPlusInterface;

/**
 * Базовый ресурс проекта, расширяющий Hyperf\Resource\Json\JsonResource.
 */
abstract class JsonResource extends BaseJsonResource
{
    public static bool $from_collection = false;
    public static bool $force_is_parent = false;

    /**
     * Дополнительные ключи для сериализации.
     *
     * @var array{properties: string[], relationships: string[]}
     */
    public array $additions = [
        'properties' => [],
        'relationships' => [],
    ];

    public bool $is_parent = false;
    public bool $ignore_properties_if_parents = false;

    /**
     * @param mixed $resource
     */
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
        $this->resource = $resource;
    }

    /**
     * @param bool $condition
     * @param Closure $closure
     * @return array<mixed>
     */
    public function mergeWhenByClosure(bool $condition, Closure $closure): mixed
    {
        if ($condition) {
            return $this->merge($closure($this));
        }

        return new MissingValue();
    }

    /**
     * Проверяет наличие параметра with[properties]/with[relationships] в запросе.
     */
    public function hasWith(string $key, bool $force_has_with = false): bool
    {
        $key_array = explode('.', $key);

        if (count($key_array) < 2) {
            return false;
        }

        [$group, $field] = $key_array;

        if ($group === 'properties') {
            $this->additions['properties'][] = $field;

            if ($this->is_parent) {
                if ($this->ignore_properties_if_parents) {
                    return false;
                }

                return ! $force_has_with || $this->hasWithInRequest($group, $field);
            }

            return $this->hasWithInRequest($group, $field);
        }

        if ($group === 'relationships') {
            $this->additions['relationships'][] = $field;

            if ($this->is_parent) {
                return ! $force_has_with || $this->hasWithInRequest($group, $field);
            }

            $hasInRequest = $this->hasWithInRequest($group, $field);
            $relationLoaded = true;

            if (is_object($this->resource) && method_exists($this->resource, 'relationLoaded')) {
                $relationLoaded = $this->resource->relationLoaded($field);
            }

            return $hasInRequest && $relationLoaded;
        }

        return false;
    }

    /**
     * Проверка параметра with[...] в HTTP-запросе Hyperf.
     */
    protected function hasWithInRequest(string $section, string $name): bool
    {
        /** @var ServerRequestPlusInterface|null $serverRequest */
        $serverRequest = Context::get(ServerRequestPlusInterface::class);
        if ($serverRequest === null) {
            // Нет активного HTTP-запроса (CLI, воркер-старт, крон и т.д.)
            return false;
        }

        $with = $serverRequest->getQueryParams()['with'] ?? [];
        if (is_string($with)) {
            $with = array_filter(array_map('trim', explode(',', $with)));
        }

        return in_array("$section.$name", $with, true);
    }

    /**
     * Создаёт новую коллекцию ресурсов.
     */
    public static function collection($resource): AnonymousResourceCollection
    {
        if (! self::$force_is_parent) {
            static::$from_collection = true;
        }

        return parent::collection($resource);
    }
}
