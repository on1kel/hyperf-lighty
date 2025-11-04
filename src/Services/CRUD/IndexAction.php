<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD;

use function array_key_exists;

use Closure;

use function count;
use function filter_var;

use Hyperf\Database\Model\Builder as EloquentBuilder;
use Hyperf\Database\Query\Builder as QueryBuilder;

use function in_array;
use function is_array;
use function is_null;
use function mb_stripos;

use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionClosureModeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\ActionOptionsRelationships;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\IndexActionRequestPayloadDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\IndexActionRequestPayloadFilterBooleanEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\IndexActionRequestPayloadFilterDTO;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\IndexActionRequestPayloadFilterTypeEnum;
use On1kel\HyperfLighty\Models\Model;
use On1kel\HyperfLighty\Services\CRUD\DTO\ActionClosureDataDTO;
use On1kel\HyperfLighty\Services\CRUD\Events\Index\IndexCalled;
use On1kel\HyperfLighty\Services\CRUD\Events\Index\IndexEnded;
use On1kel\HyperfLighty\Services\CRUD\Exceptions\ColumnMustBeSpecifiedException;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;

use function str_starts_with;
use function substr;

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

        if ($options->filters->enable) {
            $builder = $this->addFilters($options, $data->filter, $builder);
        }

        if ($options->orders->enable) {
            $builder = $this->addOrders($options, $data, $builder, $options->orders->default_orders);
        }

        if ($options->relationships->enable) {
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

        if ($options->pagination->enable) {
            $limit = $data->limit;
            $page = $data->page;
            $items = $builder->paginate($limit, page: $page);
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
     * @param  IndexActionOptionsDTO                     $options
     * @param  array<int, IndexActionRequestPayloadFilterDTO> $filters
     * @param  EloquentBuilder|QueryBuilder              $builder
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addFilters(
        IndexActionOptionsDTO $options,
        array $filters,
        EloquentBuilder|QueryBuilder $builder
    ): EloquentBuilder|QueryBuilder {
        if (count($filters) === 0) {
            return $builder;
        }

        return $builder->where(function (EloquentBuilder|QueryBuilder $q) use ($options, $filters) {
            foreach ($filters as $filter) {
                $q = $this->addFilter($options, $q, $filter);
            }
        });
    }

    /**
     * @param  IndexActionOptionsDTO               $options
     * @param  EloquentBuilder|QueryBuilder        $builder
     * @param  IndexActionRequestPayloadFilterDTO  $filter
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addFilter(
        IndexActionOptionsDTO $options,
        EloquentBuilder|QueryBuilder $builder,
        IndexActionRequestPayloadFilterDTO $filter
    ): EloquentBuilder|QueryBuilder {
        $ignore = $options->filters->ignore;
        if ($ignore && is_array($ignore) && in_array($filter->column, $ignore, true)) {
            return $builder;
        }

        if ($filter->type === IndexActionRequestPayloadFilterTypeEnum::Group) {
            $inside = function (EloquentBuilder|QueryBuilder $q) use ($options, $filter) {
                foreach ($filter->group as $insideFilter) {
                    $q = $this->addFilter($options, $q, $insideFilter);
                }
            };

            if ($filter->boolean === IndexActionRequestPayloadFilterBooleanEnum::And) {
                return $builder->where($inside);
            }

            return $builder->orWhere($inside);
        }

        $column = $filter->column;
        if (! $column) {
            throw new ColumnMustBeSpecifiedException();
        }

        $operator = $filter->operator->value;
        $value = $filter->value;
        $boolean = $filter->boolean->value;

        if (! mb_stripos($column, '.')) {
            $column = $this->currentModel->getTable() . '.' . $column;
        }

        if (is_array($value)) {
            // whereIn(column, values, boolean = 'and'|'or', not = true|false)
            return $builder->whereIn($column, $value, $boolean, $operator !== '=');
        }

        return $builder->where($column, $operator, $value, $boolean);
    }

    /**
     * @param  IndexActionOptionsDTO          $options
     * @param  IndexActionRequestPayloadDTO   $request
     * @param  EloquentBuilder|QueryBuilder   $builder
     * @param  array<int,string>              $default_orders
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addOrders(
        IndexActionOptionsDTO $options,
        IndexActionRequestPayloadDTO $request,
        EloquentBuilder|QueryBuilder $builder,
        array $default_orders
    ): EloquentBuilder|QueryBuilder {
        $orders = $request->order ?: $default_orders;

        foreach ($orders as $order) {
            // корректная санитизация:
            $order = (string) filter_var($order, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $builder = $this->addOrder($options, $builder, $order);
        }

        return $builder;
    }

    /**
     * @param  IndexActionOptionsDTO        $options
     * @param  EloquentBuilder|QueryBuilder $builder
     * @param  string                       $order
     * @return EloquentBuilder|QueryBuilder
     */
    protected function addOrder(
        IndexActionOptionsDTO $options,
        EloquentBuilder|QueryBuilder $builder,
        string $order
    ): EloquentBuilder|QueryBuilder {
        $direction = 'asc';

        if (str_starts_with($order, '-')) {
            $direction = 'desc';
            $order = substr($order, 1);
        }

        if ($options->orders->null_control) {
            // NULLS FIRST/LAST — через raw, с биндингом колонки
            $builder->orderByRaw(
                sprintf('? %s NULLS %s', $direction, $options->orders->null_position->value),
                [$order]
            );
        } else {
            $builder->orderBy($order, $direction);
        }

        return $builder;
    }

    /**
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
