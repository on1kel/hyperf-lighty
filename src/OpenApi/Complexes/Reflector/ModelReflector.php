<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\Reflector;

use Hyperf\Database\Model\Collection;
use On1kel\HyperfLighty\Http\Resources\CollectionResource;
use On1kel\HyperfLighty\Http\Resources\SingleResource;
use On1kel\HyperfLighty\OpenApi\Complexes\Reflector\DTO\ModelPropertyDTO;
use On1kel\OAS\Builder\Schema\Schema;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;
use Throwable;
use UnitEnum;

/**
 * ModelReflector:
 *
 * 1) Строит "виртуальную" модель с фейковыми значениями и вложенными отношениями.
 * 2) Прогоняет эту модель через твой Resource (SingleResource / CollectionResource).
 * 3) Получает финальный payload (массив).
 * 4) На основании payload строит схемы OpenAPI через on1kel/oas-builder.
 *
 * Ключевые публичные методы:
 *  - schemaForCollection()  -> Schema (array of objects)
 *  - getSchemaForSingle()   -> Schema (object)
 *  - getCollectionColumns() -> string[] для фильтров
 *  - getScalarFieldNames()  -> string[]
 *  - getCollectionAdditions() -> {relationships[], properties[]}
 *
 * ВНИМАНИЕ:
 * В билдере Schema нет публичной инспекции полей, поэтому методы,
 * которым нужны имена свойств (columns, additions), не будут ломать
 * Schema обратно. Они сразу работают с payload массива.
 */
final class ModelReflector
{
    private const MAX_DEPTH = 1;

    public function __construct(
        private readonly IdeHelperModelsReaderInterface $ideReader
    ) {
    }

    # ============ ПУБЛИЧНЫЙ API ============

    /**
     * Построить схему для коллекции ресурсов.
     *
     * Возвращает Schema::array()->items(<Schema объекта элемента>)
     *
     * @param class-string $modelClass
     * @param class-string<CollectionResource<mixed>> $collectionResource
     */
    public function schemaForCollection(string $modelClass, string $collectionResource): Schema
    {
        // Определяем класс одиночного ресурса (SingleResource), который коллекция использует
        $singleClass = $this->guessSingleResourceFromCollection($collectionResource);
        if (
            ! is_string($singleClass)
            || ! is_a($singleClass, SingleResource::class, true)
        ) {
            /** @var CollectionResource<int, SingleResource> $probe */
            $probe = new $collectionResource([]);

            // public $collects
            if (property_exists($probe, 'collects')) {
                /** @var class-string<SingleResource>|null $candidate */
                $candidate = $probe->collects;
                if (is_a($candidate, SingleResource::class, true)) {
                    $singleClass = $candidate;
                }
            }

            // method collects()
            if (! isset($singleClass) && method_exists($probe, 'collects')) {
                /** @var class-string<SingleResource>|null $candidate */
                $candidate = $probe->collects();
                if (is_string($candidate) && is_a($candidate, SingleResource::class, true)) {
                    $singleClass = $candidate;
                }
            }

            if (
                ! is_string($singleClass) ||
                ! is_a($singleClass, SingleResource::class, true)
            ) {
                throw new RuntimeException("Не удалось определить SingleResource для $collectionResource");
            }
        }

        // Достаём описание полей модели и строим виртуальный объект
        $props = $this->collectModelProperties($modelClass);
        $vm = $this->buildVirtualModelFor($modelClass, $props);

        // Гоним через SingleResource
        /** @var SingleResource $single */
        $single = new $singleClass($vm, true, true);
        $payload = $this->resolveResourceToArray($single);

        // Если resource вернул { data: { ... } }, разворачиваем data
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        // Строим схему элемента
        $itemSchema = $this->inferSchemaFromAssocArray($payload);

        // Возвращаем схему МАССИВА элементов
        return Schema::array()
            ->items($itemSchema);
    }

    public function schemaForSingleFromCollection(string $modelClass, string $collectionResource): Schema
    {
        $singleClass = $this->guessSingleResourceFromCollection($collectionResource);
        if (! is_string($singleClass)) {
            // fallback если не смогли угадать
            throw new \RuntimeException("Не удалось определить SingleResource для $collectionResource");
        }

        return $this->getSchemaForSingle($modelClass, $singleClass);
    }

    /**
     * Построить схему одиночного ресурса (Schema::object() ...).
     *
     * @param class-string $modelClass
     * @param class-string<SingleResource> $singleResource
     */
    public function getSchemaForSingle(string $modelClass, string $singleResource): Schema
    {
        $props = $this->collectModelProperties($modelClass);
        $vm = $this->buildVirtualModelFor($modelClass, $props);

        /** @var SingleResource $single */
        $single = new $singleResource($vm, true, true);
        $payload = $this->resolveResourceToArray($single);

        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        return $this->inferSchemaFromAssocArray($payload);
    }

    /**
     * Имена колонок коллекции для enum фильтров.
     *
     * Раньше брали их из SchemaDTO->items->properties.
     * Теперь мы не будем пытаться introspect Schema билдера (он не обязан это уметь).
     * Мы просто повторим генерацию payload элемента и возьмём его ключи.
     */
    public function getCollectionColumns(string $modelClass, string $collectionResource): array
    {
        $payload = $this->sampleCollectionItemPayload($modelClass, $collectionResource);
        if (! is_array($payload)) {
            return ['id'];
        }

        // интересуют только скалярные поля первого уровня
        $columns = [];
        foreach ($payload as $name => $value) {
            if (! is_string($name)) {
                continue;
            }
            if (
                is_string($value) ||
                is_int($value) ||
                is_float($value) ||
                is_bool($value) ||
                $value === null
            ) {
                $columns[] = $name;
            }
        }

        return $columns !== [] ? array_values(array_unique($columns)) : ['id'];
    }

    /**
     * Дополнительные поля, которые можно запросить через with{}:
     *  - relationships: вложенные объекты/массивы объектов
     *  - properties: скаляры
     *
     * Раньше мы это вытаскивали из SchemaDTO.
     * Теперь то же самое делаем напрямую из payload.
     */
    public function getCollectionAdditions(string $modelClass, string $collectionResource): object
    {
        // Попробуем честно вытащить additions из ресурса (если ресурс сам их помечает)
        $singleClass = $this->guessSingleResourceFromCollection($collectionResource);
        $vm = $this->buildVirtualModelFor($modelClass);

        /** @var SingleResource $single */
        $single = new $singleClass($vm, true, true);
        $single->toArray(); // побочный эффект: ресурс может заполнить $additions

        $rels = $single->additions['relationships'] ?? [];
        $props = $single->additions['properties'] ?? [];

        if (
            is_array($rels) && is_array($props) &&
            ($rels !== [] || $props !== [])
        ) {
            return (object)[
                'relationships' => array_values(array_unique($rels)),
                'properties' => array_values(array_unique($props)),
            ];
        }

        // Фолбэк: эвристика по payload.
        $payload = $this->sampleCollectionItemPayload($modelClass, $collectionResource);
        $relationships = [];
        $properties = [];

        if (is_array($payload)) {
            foreach ($payload as $name => $value) {
                // отношения: объект или массив объектов
                if (
                    is_array($value) &&
                    (
                        $this->isAssoc($value) ||                    // вложенный объект
                        (isset($value[0]) && is_array($value[0]))    // массив объектов
                    )
                ) {
                    $relationships[] = $name;

                    continue;
                }

                // простое поле
                if (
                    is_string($value) ||
                    is_int($value) ||
                    is_float($value) ||
                    is_bool($value) ||
                    $value === null
                ) {
                    $properties[] = $name;
                }
            }
        }

        return (object)[
            'relationships' => array_values(array_unique($relationships)),
            'properties' => array_values(array_unique($properties)),
        ];
    }

    /**
     * Плоский список имён всех свойств модели.
     * Используется как раньше в getFlattenModelProperties().
     */
    public function getFlattenModelProperties(string $modelClass): array
    {
        $all = $this->getModelFields($modelClass);

        return array_values(array_map(
            static fn (array $p) => $p['name'],
            $all
        ));
    }

    /**
     * Список только скалярных полей модели (string|integer|number|boolean),
     * без timestamp'ов и служебных.
     */
    public function getScalarFieldNames(string $modelClass): array
    {
        $all = $this->getModelFields($modelClass);

        $scalars = array_filter($all, static function (array $p): bool {
            return in_array($p['type'], ['string', 'integer', 'number', 'boolean'], true);
        });

        $scalars = array_filter($scalars, static function (array $p): bool {
            return ! in_array($p['name'], ['created_at', 'updated_at', 'deleted_at'], true);
        });

        return array_values(array_map(static fn (array $p) => $p['name'], $scalars));
    }

    /**
     * Возвращает массив полей модели (name, type, nullable, description, readonly).
     * Это нужно для фильтров, enum'ов и т.д.
     */
    public function getModelFields(string $modelClass): array
    {
        // 1) попробовать брать из ide-helper
        $map = $this->ideReader->getPropertiesMap();

        $norm = ltrim($modelClass, '\\');
        $raw = $map[$modelClass] ?? $map['\\' . $norm] ?? $map[$norm] ?? null;

        if (is_array($raw) && $raw !== []) {
            return array_values(array_map(function (array $p): array {
                $type = (string)($p['type'] ?? 'mixed');
                $nullable = (bool)($p['nullable'] ?? false);

                $mapped = $this->mapPhpTypeToScheme($type);
                $kindEnum = $mapped['type'];
                $kind = is_object($kindEnum) && property_exists($kindEnum, 'value')
                    ? $kindEnum->value
                    : (string)$kindEnum;

                return [
                    'name' => (string)($p['name'] ?? ''),
                    'type' => $kind,
                    'nullable' => (bool)($nullable || $mapped['nullable']),
                    'description' => $p['description'] ?? null,
                    'readonly' => (bool)($p['readonly'] ?? false),
                ];
            }, $raw));
        }

        // 2) fallback — через collectModelProperties()
        $dto = $this->collectModelProperties($modelClass);

        return array_map(function ($d): array {
            /** @var ModelPropertyDTO|array $d */
            $name = is_array($d) ? ($d['name'] ?? '') : $d->name;
            $nullable = is_array($d) ? (bool)($d['nullable'] ?? false) : (bool)$d->nullable;
            $typeRaw = is_array($d) ? ($d['type'] ?? 'string') : $d->type;
            $kind = is_string($typeRaw) ? $typeRaw : $typeRaw->value;

            return [
                'name' => (string)$name,
                'type' => (string)$kind,
                'nullable' => $nullable,
                'description' => is_array($d) ? ($d['description'] ?? null) : $d->description,
                'readonly' => is_array($d) ? (bool)($d['read_only'] ?? false) : (bool)($d->read_only ?? false),
            ];
        }, $dto);
    }

    /**
     * Вернуть имена отношений модели (object или array of object).
     * Основано на getModelFields().
     */
    public function getRelationshipNames(string $modelClass): array
    {
        $all = $this->getModelFields($modelClass);
        $rels = array_filter($all, static function (array $p): bool {
            return in_array($p['type'], ['object', 'array'], true);
        });

        return array_values(array_map(static fn (array $p) => $p['name'], $rels));
    }

    # ============ ВСПОМОГАТЕЛЬНЫЕ ШАГИ ДЛЯ PAYLOAD / SCHEMA ============

    /**
     * Возвращает пример payload одного элемента коллекции:
     *  [
     *    'id' => 123,
     *    'title' => '...',
     *    'author' => [ ... ],
     *    'tags' => [ ... ],
     *    ...
     *  ]
     *
     * Используется там, где нам нужно "глядеть в данные" для фильтров,
     * а не introspect'ить Schema.
     */
    private function sampleCollectionItemPayload(string $modelClass, string $collectionResource): mixed
    {
        $schema = $this->schemaForCollection($modelClass, $collectionResource);

        // Мы не можем introspect'ить Schema билдера, поэтому делаем то же, что schemaForCollection,
        // но возвращаем не Schema, а сам payload элемента.
        $singleClass = $this->guessSingleResourceFromCollection($collectionResource);

        if (
            ! is_string($singleClass) ||
            ! is_a($singleClass, SingleResource::class, true)
        ) {
            // Попробуем так же, как в schemaForCollection()
            /** @var CollectionResource<int, SingleResource> $probe */
            $probe = new $collectionResource([]);

            if (property_exists($probe, 'collects')) {
                $candidate = $probe->collects;
                if (is_a($candidate, SingleResource::class, true)) {
                    $singleClass = $candidate;
                }
            }
            if (! isset($singleClass) && method_exists($probe, 'collects')) {
                $candidate = $probe->collects();
                if (is_string($candidate) && is_a($candidate, SingleResource::class, true)) {
                    $singleClass = $candidate;
                }
            }
        }

        if (
            ! is_string($singleClass) ||
            ! is_a($singleClass, SingleResource::class, true)
        ) {
            return null;
        }

        $props = $this->collectModelProperties($modelClass);
        $vm = $this->buildVirtualModelFor($modelClass, $props);

        /** @var SingleResource $single */
        $single = new $singleClass($vm, true, true);
        $payload = $this->resolveResourceToArray($single);
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        return $payload;
    }

    /**
     * Преобразует ресурс (SingleResource) в плоский массив без MergeValue/MissingValue.
     * Рекурсивно разворачивает вложенные ресурсы, коллекции и т.д.
     */
    private function resolveResourceToArray(SingleResource $res): array
    {
        $data = method_exists($res, 'resolve')
            ? $res->resolve()
            : $res->toArray();

        return $this->deepResolveResources($data);
    }

    /**
     * Рекурсивное раскручивание значений ресурса:
     *  - JsonResource / ResourceCollection / AnonymousResourceCollection
     *  - Hyperf\Collection / Illuminate\Support\Collection
     *  - JsonSerializable
     *  - объекты с toArray()
     */
    private function deepResolveResources(mixed $value): mixed
    {
        // ресурсы
        if (
            $value instanceof \Hyperf\Resource\Json\JsonResource ||
            $value instanceof \Hyperf\Resource\Json\AnonymousResourceCollection ||
            $value instanceof \Hyperf\Resource\Json\ResourceCollection
        ) {
            $resolved = method_exists($value, 'resolve')
                ? $value->resolve()
                : $value->toArray();

            return $this->deepResolveResources(
                $this->unwrapDataIfNeeded($resolved)
            );
        }

        // коллекции
        if ($value instanceof \Hyperf\Collection\Collection || $value instanceof \Illuminate\Support\Collection) {
            return $value
                ->map(fn ($v) => $this->deepResolveResources($v))
                ->all();
        }

        // JsonSerializable
        if ($value instanceof \JsonSerializable) {
            return $this->deepResolveResources($value->jsonSerialize());
        }

        // массив
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->deepResolveResources($v);
            }

            return $out;
        }

        // объект с toArray()
        if (is_object($value) && method_exists($value, 'toArray')) {
            $arr = $value->toArray();

            return is_array($arr)
                ? $this->deepResolveResources($arr)
                : $arr;
        }

        // скаляр / null
        return $value;
    }

    /** Если массив вида ['data' => ...] и больше ничего — достать ... */
    private function unwrapDataIfNeeded(mixed $v): mixed
    {
        return (is_array($v) && array_key_exists('data', $v) && count($v) === 1)
            ? $v['data']
            : $v;
    }

    /**
     * Построить Schema билдера по ассоциативному массиву payload-а ресурса.
     *
     * - Если передан массив с числовыми ключами -> это массив, items = схема первого элемента.
     * - Если передан ассоциативный массив -> это объект с properties.
     */
    private function inferSchemaFromAssocArray(array $data): Schema
    {
        // Если список (0..n)
        if (array_is_list($data)) {
            $first = $data[0] ?? null;
            $itemSchema = $this->inferSchemaFromValue($first);

            return Schema::array('')
                ->items($itemSchema);
        }

        // Фильтруем только string-ключи
        $assocOnly = [];
        foreach ($data as $k => $v) {
            if (is_string($k)) {
                $assocOnly[$k] = $v;
            }
        }

        // Строим properties(...)
        $props = [];
        foreach ($assocOnly as $name => $value) {
            $props[] = $this->inferSchemaProperty($name, $value);
        }


        return Schema::object()
            ->properties(...$props);
    }

    /**
     * Построить Schema билдера по произвольному значению.
     * Используется внутри inferSchemaFromAssocArray.
     */
    private function inferSchemaFromValue(mixed $value): Schema
    {
        if ($value === null) {
            return Schema::string('')->nullable(true);
        }

        // скаляры
        if (is_string($value)) {
            $format = $this->detectScalarFormatByName($value);

            $s = Schema::string('');
            if ($format !== null) {
                $s = $s->format($format);
            }

            return $s->example($value);
        }
        if (is_int($value)) {
            return Schema::integer('')->example($value);
        }
        if (is_float($value)) {
            return Schema::number('')->example($value);
        }
        if (is_bool($value)) {
            return Schema::boolean('')->example($value);
        }

        // массив
        if (is_array($value)) {
            if ($this->isAssoc($value)) {
                // объект
                return $this->inferSchemaFromAssocArray($value);
            }

            $first = $value[0] ?? null;
            $inner = $this->inferSchemaFromValue($first);

            return Schema::array('')->items($inner);
        }

        // объект
        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                $arr = $value->toArray();
                if (is_array($arr)) {
                    return $this->inferSchemaFromAssocArray($arr);
                }
            }

            // неизвестный объект -> пустой object
            return Schema::object();
        }

        // fallback
        return Schema::string();
    }

    /**
     * Построить Schema свойства объекта: Schema::<type>('field')->...
     */
    private function inferSchemaProperty(string $name, mixed $value): Schema
    {
        // null -> nullable string
        if ($value === null) {
            return Schema::string($name)->nullable(true);
        }

        if (is_string($value)) {
            $format = $this->detectScalarFormatByName($value);

            $p = Schema::string($name);
            if ($format !== null) {
                $p = $p->format($format);
            }

            return $p->example($value);
        }
        if (is_int($value)) {
            return Schema::integer($name)->example($value);
        }
        if (is_float($value)) {
            return Schema::number($name)->example($value);
        }
        if (is_bool($value)) {
            return Schema::boolean($name)->example($value);
        }

        if (is_array($value)) {
            // ассоциативный массив -> объект
            if ($this->isAssoc($value)) {
                // объект (ассоциативный массив)
                $objectSchema = $this->inferSchemaFromAssocArray($value);

                // просто добавляем имя
                return $objectSchema->named($name);
            }

            // массив значений -> array
            $first = $value[0] ?? null;
            $items = $this->inferSchemaFromValue($first);

            return Schema::array($name)->items($items);
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                $arr = $value->toArray();
                if (is_array($arr)) {
                    $objectSchema = $this->inferSchemaFromAssocArray($arr);

                    return Schema::object($name)
                        ->propertiesNamed([
                            $name => $objectSchema,
                        ]);
                }
            }

            // неизвестный объект -> пустой object
            return Schema::object()
                ->propertiesNamed([
                    $name => Schema::object(),
                ]);
        }

        // fallback
        return Schema::string($name);
    }

    /**
     * Ассоциативный массив?
     */
    private function isAssoc(array $arr): bool
    {
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i++) {
                return true;
            }
        }

        return false;
    }

    /**
     * Вычисляем "формат" строк по эвристике имён (даты, uuid, email, url).
     */
    private function detectScalarFormatByName(string $value): ?string
    {
        $v = strtolower($value);

        if (str_ends_with($v, '_at')) {
            return 'date-time';
        }

        if (str_contains($v, 'uuid')) {
            return 'uuid';
        }
        if ($v === 'email' || str_contains($v, '_email')) {
            return 'email';
        }
        if ($v === 'url' || str_contains($v, 'url') || str_contains($v, 'uri')) {
            return 'uri';
        }

        return null;
    }

    # ============ ПОСТРОЕНИЕ ВИРТУАЛЬНЫХ МОДЕЛЕЙ ============

    /**
     * Собрать виртуальную модель с фейковыми значениями, включая вложенные связи.
     *
     * @param class-string $modelClass
     * @param array<ModelPropertyDTO|array>|null $props
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    private function buildVirtualModelFor(string $modelClass, ?array $props = null): VirtualModel
    {
        if ($props === null) {
            $props = $this->collectModelProperties($modelClass);
        }

        $vm = new VirtualModel();

        foreach ($props as $property) {
            /** @var ModelPropertyDTO $dto */
            $dto = is_array($property) ? new ModelPropertyDTO(...$property) : $property;
            if ($dto->fake_value === null) {
                $dto = $dto->withFakeValue();
            }

            switch ($dto->type) {
                case SchemeTypeEnum::Integer:
                case SchemeTypeEnum::Number:
                case SchemeTypeEnum::Boolean:
                case SchemeTypeEnum::String:
                    $vm->{$dto->name} = $dto->fake_value;

                    break;

                case SchemeTypeEnum::Single: {
                    $child = new VirtualModel();
                    foreach ($dto->related_properties as $rp) {
                        $rp = $rp->withFakeValue();
                        if ($rp->type === SchemeTypeEnum::Single) {
                            $child->setRelation($rp->name, $this->buildChildVirtualModel($rp));
                        } elseif ($rp->type === SchemeTypeEnum::Collection) {
                            $child->setRelation($rp->name, [$this->buildChildVirtualModel($rp)]);
                        } else {
                            $child->{$rp->name} = $rp->fake_value;
                        }
                    }
                    $vm->setRelation($dto->name, $child);

                    break;
                }

                case SchemeTypeEnum::Collection: {
                    $child = new VirtualModel();
                    foreach ($dto->related_properties as $rp) {
                        $rp = $rp->withFakeValue();
                        if ($rp->type === SchemeTypeEnum::Single) {
                            $child->setRelation($rp->name, $this->buildChildVirtualModel($rp));
                        } elseif ($rp->type === SchemeTypeEnum::Collection) {
                            $child->setRelation($rp->name, [$this->buildChildVirtualModel($rp)]);
                        } else {
                            $child->{$rp->name} = $rp->fake_value;
                        }
                    }
                    $vm->setRelation($dto->name, new Collection([$child]));

                    break;
                }
            }
        }

        return $vm;
    }

    /**
     * Построить VirtualModel для вложенных отношений.
     */
    private function buildChildVirtualModel(ModelPropertyDTO $parent): VirtualModel
    {
        $child = new VirtualModel();

        foreach ($parent->related_properties as $rp) {
            $rp = $rp->withFakeValue();

            if ($rp->type === SchemeTypeEnum::Single) {
                $nested = $this->buildChildVirtualModel($rp);
                $child->setRelation($rp->name, $nested);

                continue;
            }

            if ($rp->type === SchemeTypeEnum::Collection) {
                $nested = $this->buildChildVirtualModel($rp);
                $child->setRelation($rp->name, [$nested]);

                continue;
            }

            $child->{$rp->name} = $rp->fake_value;
        }

        return $child;
    }

    /**
     * Собрать свойства модели из ide-helper или fallback.
     *
     * @param class-string $modelClass
     * @param int $depth
     * @param array<int, string> $stack
     * @return ModelPropertyDTO[]
     * @throws UnknownProperties
     * @throws ReflectionException
     */
    private function collectModelProperties(string $modelClass, int $depth = 0, array $stack = []): array
    {
        $norm = ltrim($modelClass, '\\');

        if (in_array($norm, $stack, true) || $depth > self::MAX_DEPTH) {
            return [];
        }

        $stack[] = $norm;

        $map = $this->ideReader->getPropertiesMap();

        /** @var array<string,mixed>[] $rawProps */
        $rawProps = $map[$modelClass] ?? $map['\\' . $norm] ?? $map[$norm] ?? [];

        if (empty($rawProps) && class_exists($modelClass)) {
            $rawProps = $this->fallbackFromModel($modelClass);
        }

        $result = [];

        foreach ($rawProps as $raw) {
            $name = $raw['name'] ?? null;
            $phpType = (string)($raw['type'] ?? 'mixed');
            $description = $raw['description'] ?? null;
            $nullable = (bool)($raw['nullable'] ?? false);
            $readonly = (bool)($raw['readonly'] ?? false);

            if (! is_string($name) || $name === '') {
                continue;
            }

            $mapped = $this->mapPhpTypeToScheme($phpType);
            $typeEnum = $mapped['type'];
            $nullable = $nullable || $mapped['nullable'];

            $dto = new ModelPropertyDTO(
                name: $name,
                description: $description,
                related: null,
                nullable: $nullable,
                read_only: $readonly,
                php_type: $phpType,
                related_properties: [],
                fake_value: null,
                type: $typeEnum
            );

            if (
                $typeEnum === SchemeTypeEnum::Single ||
                $typeEnum === SchemeTypeEnum::Collection
            ) {
                $relatedModel = $this->extractRelatedModelFqcn($phpType);
                if ($relatedModel !== null && class_exists($relatedModel)) {
                    $dto->related = $relatedModel;
                    $dto->related_properties = $this->collectModelProperties(
                        $relatedModel,
                        $depth + 1,
                        $stack
                    );
                }
            }

            $dto = $dto->withFakeValue();
            $result[] = $dto;
        }

        return $result;
    }

    /**
     * Fallback: поля из fillable и id, когда ide-helper пуст.
     */
    private function fallbackFromModel(string $modelClass): array
    {
        $out = [[
            'name' => 'id',
            'type' => 'int',
            'nullable' => false,
            'description' => 'Identifier',
            'readonly' => false,
        ]];

        try {
            $rc = new ReflectionClass($modelClass);
        } catch (ReflectionException) {
            return $out;
        }

        try {
            $obj = $rc->newInstanceWithoutConstructor();

            $fillable = [];

            if (method_exists($obj, 'getFillable')) {
                $fillable = (array) $obj->getFillable();
            } elseif ($rc->hasProperty('fillable')) {
                $prop = $rc->getProperty('fillable');
                $prop->setAccessible(true);
                $value = $prop->isInitialized($obj)
                    ? $prop->getValue($obj)
                    : $prop->getDefaultValue();
                $fillable = (array) $value;
            }

            foreach ($fillable as $f) {
                if (! is_string($f) || $f === 'id') {
                    continue;
                }

                $out[] = [
                    'name' => $f,
                    'type' => 'string',
                    'nullable' => false,
                    'description' => null,
                    'readonly' => false,
                ];
            }
        } catch (Throwable) {
            // fallback mode — просто возвращаем базовый набор
        }

        return $out;
    }

    # ============ МАППИНГ ТИПОВ PHP -> SchemeTypeEnum ============

    /**
     * PHP-тип -> SchemeTypeEnum + nullable.
     *
     * Пример входа: "int", "?string", "App\Model\User", "App\Model\User[]|null"
     *
     * @return array{type: SchemeTypeEnum, nullable: bool}
     */
    private function mapPhpTypeToScheme(string $raw): array
    {
        ['parts' => $parts, 'nullable' => $nullable] = $this->splitUnion($raw);

        $lead = ltrim($parts[0] ?? 'mixed', '\\');
        $leadLower = strtolower($lead);

        // даты
        if (
            $leadLower === 'carbon\\carbon' ||
            $leadLower === 'nesbot\\carbon\\carbon' ||
            $leadLower === 'datetimeinterface' ||
            $leadLower === 'datetime' ||
            $leadLower === 'date'
        ) {
            return ['type' => SchemeTypeEnum::String, 'nullable' => $nullable];
        }

        // enum -> string
        if ($this->looksLikeEnumFqcn($lead)) {
            return ['type' => SchemeTypeEnum::String, 'nullable' => $nullable];
        }

        // коллекция / массив моделей
        $hasCollection = false;
        $arrayModelFqcn = null;

        foreach ($parts as $p) {
            if ($this->isCollectionWord($p)) {
                $hasCollection = true;
            }
            $arrayModelFqcn ??= $this->modelFromArrayPart($p); // \App\Model\Comment[] -> \App\Model\Comment
        }

        if ($hasCollection || $arrayModelFqcn) {
            return ['type' => SchemeTypeEnum::Collection, 'nullable' => $nullable];
        }

        // одиночная вложенная модель
        foreach ($parts as $p) {
            if ($this->isAppModel($p)) {
                return ['type' => SchemeTypeEnum::Single, 'nullable' => $nullable];
            }
        }

        // базовые скаляры
        return match (true) {
            in_array($leadLower, ['int', 'integer'], true)
            => ['type' => SchemeTypeEnum::Integer, 'nullable' => $nullable],
            in_array($leadLower, ['float', 'double', 'real', 'decimal'], true)
            => ['type' => SchemeTypeEnum::Number, 'nullable' => $nullable],
            in_array($leadLower, ['bool', 'boolean'], true)
            => ['type' => SchemeTypeEnum::Boolean, 'nullable' => $nullable],
            $leadLower === 'object'
            => ['type' => SchemeTypeEnum::Single, 'nullable' => $nullable],
            default
            => ['type' => SchemeTypeEnum::String, 'nullable' => $nullable],
        };
    }

    /** Разобрать union типы на части + nullable-флаг */
    private function splitUnion(string $raw): array
    {
        $raw = trim($raw);
        $nullable = false;

        if ($raw !== '' && $raw[0] === '?') {
            $nullable = true;
            $raw = substr($raw, 1);
        }

        $parts = array_values(array_filter(array_map('trim', explode('|', $raw))));
        if ($parts && array_filter($parts, static fn ($t) => strcasecmp($t, 'null') === 0)) {
            $nullable = true;
            $parts = array_values(
                array_filter(
                    $parts,
                    static fn ($t) => strcasecmp($t, 'null') !== 0
                )
            );
        }

        return ['parts' => $parts, 'nullable' => $nullable];
    }

    private function isCollectionWord(string $typePart): bool
    {
        $t = ltrim($typePart, '\\');
        $t = strtolower($t);

        return $t === 'array'
            || str_contains($t, 'collection')
            || $t === 'hyperf\\database\\model\\collection'
            || $t === 'illuminate\\support\\collection';
    }

    private function modelFromArrayPart(?string $typePart): ?string
    {
        if (! is_string($typePart) || $typePart === '') {
            return null;
        }

        // ловим \App\Model\Comment[]
        if (str_ends_with($typePart, '[]')) {
            $base = substr($typePart, 0, -2);
            $base = '\\' . ltrim($base, '\\');
            $pl = strtolower(ltrim($base, '\\'));

            if (
                str_starts_with($pl, 'app\\model\\') ||
                str_starts_with($pl, 'app\\models\\')
            ) {
                return $base;
            }
        }

        return null;
    }

    private function isAppModel(string $typePart): bool
    {
        $p = ltrim($typePart, '\\');
        $pl = strtolower($p);

        return str_starts_with($pl, 'app\\model\\')
            || str_starts_with($pl, 'app\\models\\');
    }

    private function looksLikeEnumFqcn(string $lead): bool
    {
        $fqcn = '\\' . ltrim($lead, '\\');
        if (! class_exists($fqcn)) {
            return false;
        }

        return function_exists('enum_exists')
            ? enum_exists($fqcn)
            : is_subclass_of($fqcn, UnitEnum::class);
    }

    /**
     * Достаёт FQCN связанной модели из PHPDoc типа свойства.
     * Ищет сначала `Model[]`, потом `Model`.
     */
    private function extractRelatedModelFqcn(string $raw): ?string
    {
        ['parts' => $parts] = $this->splitUnion($raw);

        foreach ($parts as $p) {
            if ($m = $this->modelFromArrayPart($p)) {
                return $m;
            }
        }

        foreach ($parts as $p) {
            if ($this->isAppModel($p)) {
                return '\\' . ltrim($p, '\\');
            }
        }

        return null;
    }

    /**
     * Пытается вывести SingleResource класс из имени CollectionResource.
     * Например: App\Http\Resources\PostCollection -> App\Http\Resources\PostResource.
     */
    private function guessSingleResourceFromCollection(string $collectionResource): ?string
    {
        $base = $collectionResource;

        if (str_ends_with($base, 'Collection')) {
            $candidate = substr($base, 0, -strlen('Collection'));

            $class = class_exists($candidate)
                ? $candidate
                : ($candidate . 'Resource');

            if (class_exists($class) && is_a($class, SingleResource::class, true)) {
                return $class;
            }
        }

        return null;
    }
}
