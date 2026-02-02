<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD;

use function array_key_exists;

use Closure;

use function count;
use function explode;

use Hyperf\Database\Model\Builder as EloquentBuilder;
use Hyperf\Database\Query\Builder as QueryBuilder;
use Hyperf\Database\Query\JoinClause;

use function in_array;
use function is_array;
use function is_null;
use function preg_replace;
use function sprintf;
use function str_contains;
use function strtoupper;

use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionClosureModeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionOptionsRelationships;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\IndexActionRequestPayloadDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Join\IndexActionRequestPayloadJoinDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Join\IndexActionRequestPayloadJoinTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Order\IndexActionRequestPayloadOrderDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Select\IndexActionRequestColumnDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where\IndexActionRequestPayloadWhereBooleanEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where\IndexActionRequestPayloadWhereDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where\IndexActionRequestPayloadWhereTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where\IndexActionRequestPayloadWhereValueTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\Exceptions\UnsupportedPointerException;
use On1kel\HyperfLighty\Models\Model;
use On1kel\HyperfLighty\Services\CRUD\DTO\ActionClosureDataDTO;
use On1kel\HyperfLighty\Services\CRUD\Events\Index\IndexCalled;
use On1kel\HyperfLighty\Services\CRUD\Events\Index\IndexEnded;
use On1kel\HyperfLighty\Services\CRUD\Exceptions\ColumnMustBeSpecifiedException;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;

class IndexAction extends BaseCRUDAction
{
    /**
     * Список разрешённых отношений для загрузки.
     * @var array<string>
     */
    protected array $allowedRelationships = [];

    /**
     * @param  Model|string|\On1kel\HyperfLightyMongoDBBundle\Models\Model  $model
     * @param  array<string>  $allowedRelationships
     */
    public function __construct(
        Model|string|\On1kel\HyperfLightyMongoDBBundle\Models\Model $model,
        array $allowedRelationships = []
    ) {
        parent::__construct($model);
        $this->setAllowedRelationships($allowedRelationships);
    }

    /**
     * Универсальный метод поиска сущностей.
     *
     * @param  EloquentBuilder|QueryBuilder|null  $builder
     * @param  IndexActionOptionsDTO              $options
     * @param  IndexActionRequestPayloadDTO       $data
     * @param  Closure|null                       $closure
     * @return mixed  Коллекция/Пагинатор/что вернёт билдер
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    public function handle(
        EloquentBuilder|QueryBuilder|null $builder,
        IndexActionOptionsDTO $options,
        IndexActionRequestPayloadDTO $data,
        ?Closure $closure = null
    ): mixed {
        $this->dispatchCrud(
            IndexCalled::class,
            $this->currentModel::class,
            $data
        );

        if (is_null($builder)) {
            /** @var EloquentBuilder $builder */
            $builder = $this->currentModel::query();
        }

        $builder = $this->getPreparedQueryBuilder($builder, $options);

        // SELECT с агрегациями
        if ($options->select->enable) {
            $builder = $this->addSelects($data->select, $builder);
        }

        // JOIN
        if ($options->join->enable) {
            $builder = $this->addJoins($data->join, $builder);
        }

        // WHERE
        if ($options->where->enable) {
            $builder = $this->addWheres($options, $data->where, $builder);
        }

        // GROUP BY
        if ($options->group_by->enable) {
            $builder = $this->addGroupBy($data->group_by, $builder);
        }

        // ORDER BY
        if ($options->orders->enable) {
            $builder = $this->addOrders($options, $data, $builder);
        }

        // Relationships - пропускаем для аналитических запросов
        if ($options->relationships->enable && ! $data->isAnalyticalQuery()) {
            $builder = $this->addRelationships($options->relationships, $data, $builder);
        }

        if ($closure) {
            $tmp = $closure(new ActionClosureDataDTO([
                'mode' => ActionClosureModeEnum::Builder,
                'data' => $builder,
            ]));
            if ($tmp) {
                $builder = $tmp;
            }
        }

        // Пагинация: для аналитических запросов используем ручной LIMIT/OFFSET
        if ($options->pagination->enable && $data->paginate) {
            if ($data->isAnalyticalQuery()) {
                $limit = $data->limit;
                $offset = ($data->page - 1) * $limit;
                $items = $builder->limit($limit)->offset($offset)->get();
            } else {
                $limit = $data->limit;
                $page = $data->page;
                $items = $builder->paginate($limit, page: $page);
            }
        } else {
            $items = $builder->get();
        }

        if ($closure) {
            $filtered = $closure(new ActionClosureDataDTO([
                'mode' => ActionClosureModeEnum::Filter,
                'data' => $items,
            ]));
            if ($filtered) {
                $items = $filtered;
            }
        }

        $this->dispatchCrud(
            IndexEnded::class,
            $this->currentModel::class,
            $data
        );

        return $items;
    }

    /**
     * Добавление SELECT с агрегациями
     *
     * @param  array<int, IndexActionRequestColumnDTO>  $columns
     * @param  EloquentBuilder|QueryBuilder             $builder
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addSelects(
        array $columns,
        EloquentBuilder|QueryBuilder $builder
    ): EloquentBuilder|QueryBuilder {
        if (count($columns) === 0) {
            return $builder;
        }

        foreach ($columns as $column) {
            if ($column->aggregation !== null) {
                $builder = $this->applyAggregation($builder, $column);

                continue;
            }

            // Обычная колонка
            $columnName = $column->column;

            if ($columnName === '*') {
                $builder->addSelect($this->currentModel->getTable() . '.*');

                continue;
            }

            $completedColumn = $this->completePointer($columnName);
            $alias = $column->alias;

            // Генерируем алиас для внешних таблиц (если колонка содержит точку и это не текущая таблица)
            if ($alias === null && str_contains($completedColumn, '.')) {
                $parts = explode('.', $completedColumn);
                if ($parts[0] !== $this->currentModel->getTable()) {
                    $alias = $parts[0] . '_' . $parts[1];
                }
            }

            if ($alias !== null) {
                $safeColumn = $this->clearPointer($completedColumn);
                $safeAlias = $this->clearPointer($alias);
                $builder->selectRaw(sprintf('%s as %s', $safeColumn, $safeAlias));
            } else {
                $builder->addSelect($completedColumn);
            }
        }

        return $builder;
    }

    /**
     * Применение агрегации к колонке
     *
     * @param  EloquentBuilder|QueryBuilder    $builder
     * @param  IndexActionRequestColumnDTO     $column
     * @return EloquentBuilder|QueryBuilder
     */
    protected function applyAggregation(
        EloquentBuilder|QueryBuilder $builder,
        IndexActionRequestColumnDTO $column
    ): EloquentBuilder|QueryBuilder {
        $aggregation = $column->aggregation;
        $columnName = $column->column;
        $alias = $column->alias;

        if ($columnName !== '*') {
            $columnName = $this->completePointer($columnName);
        }

        $safeColumn = $this->clearPointer($columnName);
        $aggregationUpper = strtoupper($aggregation->value);

        if ($alias !== null) {
            $safeAlias = $this->clearPointer($alias);
            $builder->selectRaw(sprintf('%s(%s) as %s', $aggregationUpper, $safeColumn, $safeAlias));
        } else {
            // Генерируем алиас автоматически: count_column, sum_column и т.д.
            $autoAlias = $aggregation->value . '_' . str_replace('.', '_', $safeColumn);
            $builder->selectRaw(sprintf('%s(%s) as %s', $aggregationUpper, $safeColumn, $autoAlias));
        }

        return $builder;
    }

    /**
     * Валидация и дополнение указателя на колонку
     *
     * @param  string  $pointer
     * @return string
     * @throws UnsupportedPointerException
     */
    protected function completePointer(string $pointer): string
    {
        $pointer = $this->clearPointer($pointer);
        $parts = explode('.', $pointer);

        // Максимум 2 уровня: table.column
        if (count($parts) > 2) {
            throw new UnsupportedPointerException(
                sprintf('Pointer "%s" has more than 2 levels. Only table.column format is supported.', $pointer)
            );
        }

        // Если 1 уровень - добавляем текущую таблицу
        if (count($parts) === 1) {
            return $this->currentModel->getTable() . '.' . $pointer;
        }

        return $pointer;
    }

    /**
     * Очистка указателя от небезопасных символов
     *
     * @param  string  $pointer
     * @return string
     */
    protected function clearPointer(string $pointer): string
    {
        // Оставляем только a-z, 0-9, _, .
        return (string) preg_replace('/[^a-z0-9_.*]/i', '', $pointer);
    }

    /**
     * Санитизация имени таблицы
     *
     * @param  string  $tableName
     * @return string
     */
    protected function sanitizeTableName(string $tableName): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    }

    /**
     * Добавление JOIN-ов
     *
     * @param  array<int, IndexActionRequestPayloadJoinDTO>  $joins
     * @param  EloquentBuilder|QueryBuilder                   $builder
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addJoins(
        array $joins,
        EloquentBuilder|QueryBuilder $builder
    ): EloquentBuilder|QueryBuilder {
        foreach ($joins as $join) {
            $builder = $this->addJoin($join, $builder);
        }

        return $builder;
    }

    /**
     * Добавление одного JOIN
     *
     * @param  IndexActionRequestPayloadJoinDTO  $join
     * @param  EloquentBuilder|QueryBuilder      $builder
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addJoin(
        IndexActionRequestPayloadJoinDTO $join,
        EloquentBuilder|QueryBuilder $builder
    ): EloquentBuilder|QueryBuilder {
        $table = $this->sanitizeTableName($join->table);
        $leftColumn = $this->completePointer($join->on->left);
        $operator = $join->on->operator->value;
        $rightColumn = $this->completePointer($join->on->right);

        $callback = function (JoinClause $joinClause) use ($leftColumn, $operator, $rightColumn, $join) {
            $joinClause->on($leftColumn, $operator, $rightColumn);

            // Дополнительные WHERE условия для JOIN
            if (count($join->where) > 0) {
                foreach ($join->where as $where) {
                    $this->addJoinWhere($joinClause, $where);
                }
            }
        };

        return match ($join->type) {
            IndexActionRequestPayloadJoinTypeEnum::LEFT => $builder->leftJoin($table, $callback),
            IndexActionRequestPayloadJoinTypeEnum::RIGHT => $builder->rightJoin($table, $callback),
            IndexActionRequestPayloadJoinTypeEnum::INNER => $builder->join($table, $callback),
            IndexActionRequestPayloadJoinTypeEnum::FULL => $builder->crossJoin($table),
        };
    }

    /**
     * Добавление WHERE условия внутри JOIN
     *
     * @param  JoinClause                          $joinClause
     * @param  IndexActionRequestPayloadWhereDTO   $where
     * @return void
     */
    protected function addJoinWhere(
        JoinClause $joinClause,
        IndexActionRequestPayloadWhereDTO $where
    ): void {
        if ($where->column === null) {
            return;
        }

        $column = $this->completePointer($where->column);
        $operator = $where->operator->value;
        $value = $where->value;
        $boolean = $where->boolean->value;

        if ($where->value_type === IndexActionRequestPayloadWhereValueTypeEnum::Pointer) {
            // Сравнение колонка-колонка
            $rightColumn = $this->completePointer((string) $value);
            if ($boolean === 'and') {
                $joinClause->on($column, $operator, $rightColumn);
            } else {
                $joinClause->orOn($column, $operator, $rightColumn);
            }
        } else {
            // Сравнение колонка-значение
            $joinClause->where($column, $operator, $value, $boolean);
        }
    }

    /**
     * Добавление WHERE условий
     *
     * @param  IndexActionOptionsDTO                        $options
     * @param  array<int, IndexActionRequestPayloadWhereDTO> $wheres
     * @param  EloquentBuilder|QueryBuilder                  $builder
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addWheres(
        IndexActionOptionsDTO $options,
        array $wheres,
        EloquentBuilder|QueryBuilder $builder
    ): EloquentBuilder|QueryBuilder {
        if (count($wheres) === 0) {
            return $builder;
        }

        return $builder->where(function (EloquentBuilder|QueryBuilder $q) use ($options, $wheres) {
            foreach ($wheres as $where) {
                $q = $this->addWhere($options, $q, $where);
            }
        });
    }

    /**
     * Добавление одного WHERE условия
     *
     * @param  IndexActionOptionsDTO               $options
     * @param  EloquentBuilder|QueryBuilder        $builder
     * @param  IndexActionRequestPayloadWhereDTO   $where
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addWhere(
        IndexActionOptionsDTO $options,
        EloquentBuilder|QueryBuilder $builder,
        IndexActionRequestPayloadWhereDTO $where
    ): EloquentBuilder|QueryBuilder {
        $ignore = $options->where->ignore;
        if ($ignore && is_array($ignore) && in_array($where->column, $ignore, true)) {
            return $builder;
        }

        if ($where->type === IndexActionRequestPayloadWhereTypeEnum::Group) {
            $inside = function (EloquentBuilder|QueryBuilder $q) use ($options, $where) {
                foreach ($where->group as $insideWhere) {
                    $q = $this->addWhere($options, $q, $insideWhere);
                }
            };

            if ($where->boolean === IndexActionRequestPayloadWhereBooleanEnum::And) {
                return $builder->where($inside);
            }

            return $builder->orWhere($inside);
        }

        $column = $where->column;
        if (! $column) {
            throw new ColumnMustBeSpecifiedException();
        }

        $column = $this->completePointer($column);
        $operator = $where->operator->value;
        $value = $where->value;
        $boolean = $where->boolean->value;

        // Проверка на Pointer - сравнение колонка-колонка
        if ($where->value_type === IndexActionRequestPayloadWhereValueTypeEnum::Pointer) {
            $rightColumn = $this->completePointer((string) $value);

            return $builder->whereColumn($column, $operator, $rightColumn, $boolean);
        }

        if (is_array($value)) {
            // whereIn(column, values, boolean = 'and'|'or', not = true|false)
            return $builder->whereIn($column, $value, $boolean, $operator !== '=');
        }

        return $builder->where($column, $operator, $value, $boolean);
    }

    /**
     * Добавление GROUP BY
     *
     * @param  array<string>               $groupBy
     * @param  EloquentBuilder|QueryBuilder $builder
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addGroupBy(
        array $groupBy,
        EloquentBuilder|QueryBuilder $builder
    ): EloquentBuilder|QueryBuilder {
        if (count($groupBy) === 0) {
            return $builder;
        }

        $columns = [];
        foreach ($groupBy as $column) {
            $columns[] = $this->completePointer($column);
        }

        return $builder->groupBy($columns);
    }

    /**
     * Добавление ORDER BY
     *
     * @param  IndexActionOptionsDTO          $options
     * @param  IndexActionRequestPayloadDTO   $request
     * @param  EloquentBuilder|QueryBuilder   $builder
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addOrders(
        IndexActionOptionsDTO $options,
        IndexActionRequestPayloadDTO $request,
        EloquentBuilder|QueryBuilder $builder
    ): EloquentBuilder|QueryBuilder {
        $orders = $request->order;

        // Если нет заданных сортировок, используем default_order
        if (count($orders) === 0 && $options->orders->default_order !== null) {
            $orders = [$options->orders->default_order];
        }

        foreach ($orders as $order) {
            $builder = $this->addOrder($builder, $order);
        }

        return $builder;
    }

    /**
     * Добавление одной ORDER BY сортировки
     *
     * @param  EloquentBuilder|QueryBuilder        $builder
     * @param  IndexActionRequestPayloadOrderDTO   $order
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addOrder(
        EloquentBuilder|QueryBuilder $builder,
        IndexActionRequestPayloadOrderDTO $order
    ): EloquentBuilder|QueryBuilder {
        $column = $this->completePointer($order->column);
        $direction = $order->direction->value;
        $nullPosition = strtoupper($order->null_position->value);

        // Используем orderByRaw для контроля NULLS FIRST/LAST
        $builder->orderByRaw(
            sprintf('%s %s NULLS %s', $column, $direction, $nullPosition)
        );

        return $builder;
    }

    /**
     * Добавление отношений
     *
     * @param  ActionOptionsRelationships   $options
     * @param  IndexActionRequestPayloadDTO $request
     * @param  EloquentBuilder|QueryBuilder $builder
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addRelationships(
        ActionOptionsRelationships $options,
        IndexActionRequestPayloadDTO $request,
        EloquentBuilder|QueryBuilder $builder
    ): EloquentBuilder|QueryBuilder {
        if (! $options->enable || empty($request->with)) {
            return $builder;
        }

        $relationships = $request->with;
        if (array_key_exists('relationships', $relationships)) {
            $relationships = $relationships['relationships'];
        } else {
            return $builder;
        }

        foreach ($relationships as $relationship) {
            if ($completed = $this->currentModel->completeRelation($relationship)) {
                $builder = $this->addRelationship($builder, $completed, $options->ignore_allowed);
            }
        }

        return $builder;
    }

    /**
     * Добавление одного отношения
     *
     * @param  EloquentBuilder|QueryBuilder $builder
     * @param  string                       $relationship
     * @param  bool                         $ignore_allowed
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addRelationship(
        EloquentBuilder|QueryBuilder $builder,
        string $relationship,
        bool $ignore_allowed = false
    ): EloquentBuilder|QueryBuilder {
        /** @var EloquentBuilder $builder */
        if ($ignore_allowed || $this->checkRelationship($relationship)) {
            $builder = $builder->with($relationship);
        }

        return $builder;
    }

    /**
     * @param  array<string> $allowedRelationships
     */
    public function setAllowedRelationships(array $allowedRelationships): void
    {
        $completed = [];
        foreach ($allowedRelationships as $relationship) {
            if ($rel = $this->currentModel->completeRelation($relationship)) {
                $completed[] = $rel;
            }
        }
        $this->allowedRelationships = $completed;
    }

    /**
     * @return array<string>
     */
    protected function getAllowedRelationships(): array
    {
        return $this->allowedRelationships;
    }

    protected function checkRelationship(string $relationship): bool
    {
        return in_array($relationship, $this->getAllowedRelationships(), true);
    }
}
