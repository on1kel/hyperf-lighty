<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Resources;

use ArrayIterator;

use function class_exists;

use Countable;
use Hyperf\Collection\Collection;
use Hyperf\Resource\Json\JsonResource as BaseJsonResource;

use function is_a;
use function is_array;
use function is_object;
use function is_string;
use function iterator_to_array;

use IteratorAggregate;
use LogicException;

use function method_exists;

use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

use function str_ends_with;
use function strlen;
use function strrpos;
use function substr;

use Traversable;

/**
 * @template T of BaseJsonResource
 * @implements IteratorAggregate<int, T>
 */
abstract class CollectionResource extends JsonResource implements Countable, IteratorAggregate
{
    /** Класс ресурса-элемента (например, PostResource::class). */
    public string $collects = '';

    /**
     * Коллекция ресурсов-элементов.
     *
     * @var Collection<int, T>
     */
    public Collection $collection;

    /** Добавлять все query-параметры к пагинационным ссылкам. */
    protected bool $preserveAllQueryParameters = false;

    /**
     * Набор query-параметров для пагинационных ссылок.
     * @var array<string,mixed>|null
     */
    protected ?array $queryParameters = null;

    /**
     * @param mixed       $resource
     * @param string|null $single_resource_class
     */
    public function __construct($resource, ?string $single_resource_class = null)
    {
        parent::$from_collection = true;
        parent::__construct($resource);

        $this->setCollects($single_resource_class);
        $this->resource = $this->collectResource($resource);
    }

    /**
     * Преобразование входной коллекции к коллекции ресурсов.
     *
     * @param  mixed $resource
     * @return mixed
     */
    protected function collectResource($resource): mixed
    {
        if (is_array($resource)) {
            /** @var Collection<int,mixed> $tmp */
            $tmp = new Collection($resource);
            $resource = $tmp;
        }

        $collects = $this->collects();

        /** @var Collection<int, mixed> $base */
        if ($resource instanceof Collection) {
            $base = $resource;
        } elseif (is_object($resource) && method_exists($resource, 'toBase')) {
            /** @var Collection<int, mixed> $base */
            $base = $resource->toBase();
        } elseif ($this->looksLikePaginator($resource)) {
            if (! is_object($resource)) {
                /** @var array<int,mixed> $items */
                $items = [];
            } else {
                /** @var object $resource */
                $items = $this->extractPaginatorItems($resource);
            }
            /** @var Collection<int,mixed> $base */
            $base = new Collection($items);
        } else {
            /** @var Collection<int,mixed> $base */
            $base = new Collection((array) $resource);
        }

        // Маппим в указанный ресурс, если элементы ещё не являются этим ресурсом
        /** @var Collection<int, T> $mapped */
        $mapped = ($collects && ! ($base->first() instanceof $collects))
            ? $base->mapInto($collects)
            : $base;

        $this->collection = $mapped;

        // Если это пагинатор — вернём его же, но с подменённой коллекцией
        if ($this->looksLikePaginator($resource) && is_object($resource) && method_exists($resource, 'setCollection')) {
            $resource->setCollection($this->collection);

            return $resource;
        }

        return $this->collection;
    }

    /**
     * Возвращает FQCN ресурса-элемента, либо пытается вывести его из имени класса Collection.
     *
     * @return class-string<BaseJsonResource>|null
     */
    protected function collects(): ?string
    {
        $collects = null;

        if ($this->collects !== '') {
            $collects = $this->collects;
        } else {
            // Если класс заканчивается на "Collection" → пробуем вычесть суффикс
            $self = static::class;
            $base = substr($self, (strrpos($self, '\\') ?: -1) + 1); // basename без helper'а
            if (str_ends_with($base, 'Collection')) {
                $candidate = substr($self, 0, -strlen('Collection'));
                // Пробуем <FooCollection> → <Foo> или <FooResource>
                $class = class_exists($candidate) ? $candidate : ($candidate . 'Resource');
                if (class_exists($class)) {
                    $collects = $class;
                }
            }
        }

        if (! $collects || is_a($collects, BaseJsonResource::class, true)) {
            /** @var class-string<BaseJsonResource>|null $collects */
            return $collects;
        }

        throw new LogicException('Resource collections must collect instances of ' . BaseJsonResource::class . '.');
    }

    /**
     * JSON-флаги для ответа (проксируем из ресурса-элемента, если задан).
     *
     * @throws ReflectionException
     */
    public function jsonOptions(): int
    {
        $collects = $this->collects();
        if (! $collects) {
            return 0;
        }

        $reflector = new ReflectionClass($collects);
        /** @var self $instance */
        $instance = $reflector->newInstanceWithoutConstructor();

        return $instance->jsonOptions();
    }

    /**
     * Итератор по элементам коллекции.
     *
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->collection->all());
    }

    /**
     * Установка класса ресурса-элемента.
     */
    protected function setCollects(?string $single_resource_class): void
    {
        if (is_string($single_resource_class) && class_exists($single_resource_class)) {
            $this->collects = $single_resource_class;
        } else {
            // Попытка вывести из имени класса "<Name>Collection"
            $self = static::class;
            $base = substr($self, (strrpos($self, '\\') ?: -1) + 1);
            if (str_ends_with($base, 'Collection')) {
                $candidate = substr($self, 0, -strlen('Collection'));
                $class = class_exists($candidate) ? $candidate : ($candidate . 'Resource');
                if (class_exists($class)) {
                    $this->collects = $class;
                }
            }
        }

        if ($this->collects === '') {
            throw new RuntimeException('Cannot determine single resource class for collection.');
        }
    }

    /**
     * Включить проброс всех query-параметров в пагинационные ссылки.
     */
    public function preserveQuery(): self
    {
        $this->preserveAllQueryParameters = true;

        return $this;
    }

    /**
     * Указать набор query-параметров для пагинационных ссылок.
     *
     * @param array<string, mixed> $query
     */
    public function withQuery(array $query): self
    {
        $this->preserveAllQueryParameters = false;
        $this->queryParameters = $query;

        return $this;
    }

    /**
     * Количество элементов в коллекции.
     */
    public function count(): int
    {
        return $this->collection->count();
    }

    /**
     * @return array<int, array<string,mixed>|scalar|null>
     */
    public function toArray(): array
    {
        // Не меняем тип $this->collection (оставляем Collection<int, T>)
        $data = $this->collection
            ->map(
                /**
                 * @param mixed $item
                 * @return array<string,mixed>|scalar|null
                 */
                static function ($item) {
                    if ($item instanceof BaseJsonResource) {
                        /** @var array<string,mixed>|scalar|null $arr */
                        $arr = $item->toArray(); // ← без $request

                        return $arr;
                    }

                    // допускаем «сырые» элементы (если уже массивы/скаляры)
                    return $item;
                }
            )
            ->all();

        return $data;
    }

    public function toResponse(): ResponseInterface
    {
        if ($this->looksLikePaginator($this->resource)) {
            return $this->preparePaginatedResponse(); // ← без $request
        }

        // твой базовый JsonResource::toResponse() уже без параметров
        return parent::toResponse();
    }

    protected function preparePaginatedResponse(): ResponseInterface
    {
        // Если нужно пробрасывать query в пагинатор — сделай это здесь,
        // без Request-параметра (через контейнер, если требуется).
        if (is_object($this->resource) && method_exists($this->resource, 'appends')) {
            if ($this->preserveAllQueryParameters) {
                // при желании возьми Request из контейнера и пробрось $request->query()
                // иначе просто оставь как есть
            } elseif ($this->queryParameters !== null) {
                $this->resource->appends($this->queryParameters);
            }
        }

        /** @var array<string,mixed> $data */
        $data = [
            'data' => $this->toArray(), // ← без $request
        ];


        return (new PaginatedResourceResponse(data: $data, paginator: $this->resource))->toResponse();
    }

    /**
     * Эвристика: «похоже на пагинатор».
     */
    protected function looksLikePaginator(mixed $value): bool
    {
        if (! is_object($value)) {
            return false;
        }

        // Наиболее частые методы у пагинаторов
        $methods = ['perPage', 'currentPage', 'lastPage', 'total', 'items', 'setCollection'];
        foreach ($methods as $m) {
            if (! method_exists($value, $m)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Извлекаем элементы из пагинатора.
     *
     * @param object $paginator
     * @return array<int, mixed>
     */
    protected function extractPaginatorItems(object $paginator): array
    {
        if (method_exists($paginator, 'items')) {
            $items = $paginator->items();

            if ($items instanceof Collection) {
                return $items->all();
            }
            if (is_array($items)) {
                return $items;
            }
            if ($items instanceof Traversable) {
                /** @var array<int,mixed> $arr */
                $arr = iterator_to_array($items);

                return $arr;
            }

            return [];
        }

        if (method_exists($paginator, 'getCollection')) {
            $c = $paginator->getCollection();

            if ($c instanceof Collection) {
                return $c->all();
            }
            if (is_array($c)) {
                return $c;
            }
            if ($c instanceof Traversable) {
                /** @var array<int,mixed> $arr */
                $arr = iterator_to_array($c);

                return $arr;
            }

            return [];
        }

        return [];
    }
}
