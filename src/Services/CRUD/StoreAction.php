<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD;

use function array_key_exists;

use Closure;
use Hyperf\Database\Model\Relations\BelongsToMany;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionClosureModeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\StoreAction\Option\StoreActionOptionsDTO;
use On1kel\HyperfLighty\Models\Attributes\Relationships\RelationshipTypeEnum;
use On1kel\HyperfLighty\Models\Model;
use On1kel\HyperfLighty\Services\CRUD\DTO\ActionClosureDataDTO;
use On1kel\HyperfLighty\Services\CRUD\Events\Store\StoreCalled;
use On1kel\HyperfLighty\Services\CRUD\Events\Store\StoreEnded;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;
use Throwable;

class StoreAction extends BaseCRUDAction
{
    /**
     * Создание сущности.
     *
     * @param StoreActionOptionsDTO $options
     * @param array<string,mixed> $data
     * @param Closure|null $closure
     *
     * @return Model
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnknownProperties
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function handle(
        StoreActionOptionsDTO $options,
        array $data,
        ?Closure $closure = null
    ): Model {
        // Событие: начало создания
        $this->dispatchCrud(
            StoreCalled::class,
            $this->currentModel::class,
            $data
        );

        $current_model_class = $this->currentModel::class;
        /** @var Model $new_model */
        $new_model = new $current_model_class();

        if ($closure) {
            $closure(new ActionClosureDataDTO([
                'mode' => ActionClosureModeEnum::BeforeFilling,
                'data' => $new_model,
            ]));
        }

        /** @var string $column */
        foreach ($new_model->getFillable() as $column) {
            if (array_key_exists($column, $data)) {
                $new_model->setAttribute($column, $data[$column]);
            }
        }

        if ($closure) {
            $closure(new ActionClosureDataDTO([
                'mode' => ActionClosureModeEnum::AfterFilling,
                'data' => $new_model,
            ]));
        }

        $this->beginTransaction();

        try {
            $new_model->save();

            // Синхронизация BelongsToMany по *_ids
            foreach ($new_model->getLocalRelations() as $relation_name => $relation_props) {
                if ($relation_props->type === RelationshipTypeEnum::BelongsToMany) {
                    $key = helper_string_snake($relation_name) . '_ids';
                    if (array_key_exists($key, $data)) {
                        /** @var string[]|null $ids */
                        $ids = $data[$key];
                        $relation = $new_model->$relation_name();

                        if ($relation instanceof BelongsToMany) {
                            $relation->sync($ids !== null ? $ids : []);
                        }
                    }
                }
            }

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterSave,
                    'data' => $new_model,
                ]));
            }

            $this->commit();

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterCommit,
                    'data' => $new_model,
                ]));
            }

            // Догрузка отношений при необходимости
            $this->loadAllRelationshipsAfterGet();

            // Событие: успешное окончание
            $this->dispatchCrud(
                StoreEnded::class,
                $this->currentModel::class,
                $new_model
            );

            return $new_model;
        } catch (Throwable $exception) {
            // Событие: ошибка
            $this->dispatchCrud(
                StoreEnded::class,
                $this->currentModel::class,
                $new_model,
                $exception
            );

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::BeforeRollback,
                    'data' => $new_model,
                    'exception' => $exception,
                ]));
            }

            $this->rollback();

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterRollback,
                    'data' => $new_model,
                    'exception' => $exception,
                ]));
            }

            throw $exception;
        }
    }
}
