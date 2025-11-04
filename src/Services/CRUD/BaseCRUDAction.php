<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD;

use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Model\Builder as EloquentBuilder;
use Hyperf\Database\Model\SoftDeletingScope;
use Hyperf\Database\Query\Builder as QueryBuilder;
use On1kel\HyperfLighty\Exceptions\Http\ActionResponseNotFoundException;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionOptionsDeleted;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionOptionsDeletedModeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\BaseCRUDOptionDTO;
use On1kel\HyperfLighty\Models\Model;
use On1kel\HyperfLighty\Services\CRUD\Events\BaseCRUDEvent;
use On1kel\HyperfLighty\Services\CRUD\Exceptions\UndefinedCRUDEventException;
use On1kel\HyperfLighty\Services\CRUD\Exceptions\UnsupportedModelException;
use On1kel\HyperfLighty\Transaction\WithDBTransaction;
use On1kel\HyperfLighty\Transaction\WithDBTransactionInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;
use Throwable;

abstract class BaseCRUDAction implements WithDBTransactionInterface
{
    use WithDBTransaction;

    /**
     * Текущая модель (Eloquent или Mongo-модель вашего бандла).
     *
     * @var Model|\On1kel\HyperfLightyMongoDBBundle\Models\Model
     */
    protected Model|\On1kel\HyperfLightyMongoDBBundle\Models\Model $currentModel;

    /**
     * @param Model|\On1kel\HyperfLightyMongoDBBundle\Models\Model|string $model
     */
    public function __construct(Model|\On1kel\HyperfLightyMongoDBBundle\Models\Model|string $model)
    {
        $this->setCurrentModel($model);
    }

    /**
     * @param  Model|\On1kel\HyperfLightyMongoDBBundle\Models\Model|string  $currentModel
     */
    public function setCurrentModel(mixed $currentModel): void
    {
        if (is_string($currentModel)) {
            /** @var Model $currentModel */
            $currentModel = new $currentModel();
        }

        if (! is_a($currentModel, Model::class, true)) {
            $tmpClass = $currentModel::class;
            $mongodbBase = '\On1kel\HyperfLightyMongoDBBundle\Models\Model';

            if (class_exists($mongodbBase)) {
                if (! is_a($currentModel, $mongodbBase, true)) {
                    throw new UnsupportedModelException($tmpClass, $mongodbBase);
                }
            } else {
                throw new UnsupportedModelException($tmpClass, Model::class);
            }
        }

        $this->currentModel = $currentModel;
    }

    /**
     * Диспатч конкретного CRUD-события (по-Hyperf).
     *
     * @param class-string<BaseCRUDEvent> $eventClass
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function dispatchCrud(string $eventClass, string $modelClass, mixed $data, ?Throwable $exception = null): void
    {
        if (! is_a($eventClass, BaseCRUDEvent::class, true)) {
            throw new UndefinedCRUDEventException();
        }

        $container = ApplicationContext::getContainer();
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);

        /** @var BaseCRUDEvent $event */
        $event = new $eventClass($modelClass, $data, $exception);
        $dispatcher->dispatch($event);
    }

    /**
     * Получить подготовленный билдер с учётом soft-delete настроек.
     *
     * @param  EloquentBuilder|QueryBuilder  $baseBuilder
     * @param  BaseCRUDOptionDTO             $options
     * @return EloquentBuilder|QueryBuilder
     */
    protected function getPreparedQueryBuilder(
        EloquentBuilder|QueryBuilder $baseBuilder,
        BaseCRUDOptionDTO $options
    ): EloquentBuilder|QueryBuilder {
        $builder = $this->sanitizeBuilder($baseBuilder);

        return $this->implementSoftDeleteIfNeed($builder, $options->deleted);
    }

    /**
     * Очистить билдер от глобального скоупа soft-delete, если он присутствует.
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @return EloquentBuilder|QueryBuilder
     */
    protected function sanitizeBuilder(EloquentBuilder|QueryBuilder $builder): EloquentBuilder|QueryBuilder
    {
        // В Hyperf билдеры так же Macroable; проверка на макрос withTrashed (как в исходнике) не обязательна —
        // просто снимаем глобальный скоуп SoftDeletingScope, если он был.
        if (method_exists($builder, 'withoutGlobalScope')) {
            /** @var EloquentBuilder $builder */
            $builder = $builder->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $builder;
    }

    /**
     * Применить фильтрацию по soft-delete столбцу в зависимости от режима.
     *
     * @param  EloquentBuilder|QueryBuilder  $builder
     * @param  ActionOptionsDeleted          $options
     * @return EloquentBuilder|QueryBuilder
     */
    protected function implementSoftDeleteIfNeed(
        EloquentBuilder|QueryBuilder $builder,
        ActionOptionsDeleted $options
    ): EloquentBuilder|QueryBuilder {
        if (! $options->enable) {
            return $builder;
        }

        $column = $this->currentModel->getTable() . '.' . $options->column;

        switch ($options->mode) {
            case ActionOptionsDeletedModeEnum::WithoutTrashed:
                $builder = $builder->where(static function (EloquentBuilder|QueryBuilder $q) use ($column) {
                    $q->whereNull($column);
                });

                break;

            case ActionOptionsDeletedModeEnum::WithTrashed:
                // Ничего не добавляем — показываем всё.
                break;

            case ActionOptionsDeletedModeEnum::OnlyTrashed:
                $builder = $builder->where(static function (EloquentBuilder|QueryBuilder $q) use ($column) {
                    $q->whereNotNull($column);
                });

                break;
        }

        return $builder;
    }

    /**
     * Поиск модели по первичному ключу с учётом опций и отношений.
     *
     * @param  BaseCRUDOptionDTO  $options
     * @param  mixed              $key
     * @return Model
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    protected function getModelByKey(BaseCRUDOptionDTO $options, mixed $key): Model
    {
        /** @var EloquentBuilder $builder */
        $builder = $this->getPreparedQueryBuilder($this->currentModel::query(), $options);

        $primaryKey = $this->currentModel->getKeyName();
        $column = $this->currentModel->getTable() . '.' . $primaryKey;

        try {
            /** @var ?Model $model */
            $model = $builder->where($column, $key)->first();
        } catch (Throwable) {
            // Любая ошибка на поиске — трактуем как Not Found (чтобы не светить внутренности наружу)
            throw new ActionResponseNotFoundException();
        }

        if (! $model) {
            throw new ActionResponseNotFoundException();
        }

        if ($options->relationships->enable) {
            $this->loadAllRelationshipsAfterGet();
        }

        return $model;
    }

    /**
     * Догрузить все локальные отношения модели (если ваша базовая Model это поддерживает).
     *
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    protected function loadAllRelationshipsAfterGet(): void
    {
        // Предполагаем, что ваш базовый Model имеет getLocalRelations()
        // (в Hyperf стандартный Model имеет getRelations(), но не "local".
        // Если метода нет — реализуйте его в своей базе модели).
        $this->currentModel = $this->currentModel->load(array_keys($this->currentModel->getLocalRelations()));
    }
}
