<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Models;

use Hyperf\DbConnection\Model\Model as ModelBase;

use function Hyperf\Support\class_basename;

use On1kel\HyperfLighty\Models\Attributes\Relationships\Relationship;
use On1kel\HyperfLighty\Models\Attributes\Relationships\RelationshipDTO;
use On1kel\HyperfLighty\Models\UUID\UuidableContract;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;

/**
 * Базовая абстракция модели (HyPerf).
 */
abstract class Model extends ModelBase
{
    /**
     * Автогенерация UUID для моделей, реализующих UuidableContract.
     */
    public function creating(): void
    {
        if ($this instanceof UuidableContract && ! $this->getKey()) {
            $key = $this->getKeyName();
            $this->setAttribute($key, $this->generateUuid());
        }
    }

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $relations_aliases = [];

    public function getModelName(): bool|string
    {
        if ($model_name = helper_string_title(class_basename($this))) {
            return $model_name;
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getRelationsAliases(): array
    {
        return $this->relations_aliases;
    }

    /**
     * @param array<string, array<string, mixed>> $relations_aliases
     */
    public function setRelationsAliases(array $relations_aliases): void
    {
        $this->relations_aliases = $relations_aliases;
    }

    public function completeRelation(string $needle_relation): bool|string
    {
        foreach ($this->getLocalRelations() as $relation_name => $relation_properties) {
            if ($needle_relation === $relation_name) {
                return $needle_relation;
            }
            if (in_array($needle_relation, $relation_properties->aliases, true)) {
                return $relation_name;
            }
        }

        return false;
    }

    public function hasRelationByAlias(string $alias): bool
    {
        foreach ($this->relations_aliases as $relation => $aliases) {
            if ($alias === $relation || in_array($alias, $aliases, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Собирает локальные отношения, помеченные атрибутом #[Relationship(...)].
     *
     * @return RelationshipDTO[]
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    public function getLocalRelations(): array
    {
        $result = [];
        $reflection = new \ReflectionObject($this);

        foreach ($reflection->getMethods() as $method) {
            $attributes = $method->getAttributes(Relationship::class);
            if (count($attributes) === 1) {
                $attribute = $attributes[0];
                $relationship_detail = $attribute->getArguments();
                if (! isset($relationship_detail['aliases'])) {
                    $relationship_detail['aliases'] = [];
                }

                $result[$method->getName()] = new RelationshipDTO($relationship_detail);
            }
        }

        return $result;
    }

    public function getFormattedModelName(): string
    {
        $name = class_basename($this);

        return helper_string_snake($name);
    }
}
