<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD;

use function array_key_exists;

use Closure;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionClosureModeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\UpdateAction\Option\UpdateActionOptionsDTO;
use On1kel\HyperfLighty\Models\Attributes\Relationships\RelationshipTypeEnum;
use On1kel\HyperfLighty\Models\Model;
use On1kel\HyperfLighty\Services\CRUD\DTO\ActionClosureDataDTO;
use On1kel\HyperfLighty\Services\CRUD\Events\Update\UpdateCalled;
use On1kel\HyperfLighty\Services\CRUD\Events\Update\UpdateEnded;
use On1kel\HyperfLighty\Services\CRUD\Events\Update\UpdateError;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;
use Throwable;

class UpdateAction extends BaseCRUDAction
{
    /**
     * Изменение сущности.
     *
     * @param UpdateActionOptionsDTO   $options
     * @param string                   $key
     * @param array<string,mixed>      $data
     * @param Closure|null             $closure
     *
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnknownProperties
     */
    public function handle(
        UpdateActionOptionsDTO $options,
        string $key,
        array $data,
        ?Closure $closure = null
    ): Model {
        $current_model = $this->getModelByKey($options, $key);

        // Событие: обновление начато
        $this->dispatchCrud(
            UpdateCalled::class,
            $this->currentModel::class,
            $current_model
        );

        if ($closure) {
            $closure(new ActionClosureDataDTO([
                'mode' => ActionClosureModeEnum::BeforeFilling,
                'data' => $current_model,
            ]));
        }

        // Массовое заполнение только fillable полей
        foreach ($current_model->getFillable() as $column) {
            if (array_key_exists($column, $data)) {
                $current_model->setAttribute($column, $data[$column]);
            }
        }

        if ($closure) {
            $closure(new ActionClosureDataDTO([
                'mode' => ActionClosureModeEnum::AfterFilling,
                'data' => $current_model,
            ]));
        }

        $this->beginTransaction();

        try {
            $current_model->save();

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterSave,
                    'data' => $current_model,
                ]));
            }

            // Синхронизация BelongsToMany по *_ids
            foreach ($current_model->getLocalRelations() as $relation_name => $relation_props) {
                if ($relation_props->type === RelationshipTypeEnum::BelongsToMany) {
                    $idsKey = helper_string_snake($relation_name) . '_ids';
                    if (array_key_exists($idsKey, $data)) {
                        $ids = $data[$idsKey];
                        $current_model->$relation_name()->sync($ids !== null ? $ids : []);
                    }
                }
            }

            $this->commit();

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterCommit,
                    'data' => $current_model,
                ]));
            }

            // Догружаем отношения (ваша реализация в BaseCRUDAction)
            $this->loadAllRelationshipsAfterGet();

            // Событие: обновление завершено
            $this->dispatchCrud(
                UpdateEnded::class,
                $this->currentModel::class,
                $current_model
            );


            return $current_model;
        } catch (Throwable $exception) {
            // Событие: ошибка обновления
            $this->dispatchCrud(
                UpdateError::class,
                $this->currentModel::class,
                $current_model,
                $exception
            );

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::BeforeRollback,
                    'data' => $current_model,
                    'exception' => $exception,
                ]));
            }

            $this->rollback();

            if ($closure) {
                $closure(new ActionClosureDataDTO([
                    'mode' => ActionClosureModeEnum::AfterRollback,
                    'data' => $current_model,
                    'exception' => $exception,
                ]));
            }

            throw $exception;
        }
    }
}
