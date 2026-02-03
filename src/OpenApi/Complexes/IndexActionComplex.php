<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes;

use On1kel\HyperfFlyDocs\Generator\Contracts\ComplexFactoryInterface;
use On1kel\HyperfFlyDocs\Generator\DTO\ComplexResultDTO;
use On1kel\HyperfFlyDocs\Generator\Registry\ComponentsRegistry;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsExportExportTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsReturnTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Join\IndexActionRequestPayloadJoinTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Order\IndexActionRequestPayloadOrderDirectionEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Order\IndexActionRequestPayloadOrderNullPositionEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Select\IndexActionRequestPayloadAggregationsEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where\IndexActionRequestPayloadWhereOperatorEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\Where\IndexActionRequestPayloadWhereValueTypeEnum;
use On1kel\HyperfLighty\OpenApi\Complexes\IndexAction\IndexActionArgumentsDTO;
use On1kel\HyperfLighty\OpenApi\Complexes\Reflector\ModelReflector;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\ErrorResponse;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\SuccessCollectionResourceResponse;
use On1kel\OAS\Builder\Bodies\RequestBody;
use On1kel\OAS\Builder\Media\MediaType;
use On1kel\OAS\Builder\Parameters\Parameter;
use On1kel\OAS\Builder\Responses\Responses as ResponsesBuilder;
use On1kel\OAS\Builder\Schema\Schema;

final class IndexActionComplex implements ComplexFactoryInterface
{
    public function __construct(
        private readonly ModelReflector $model_reflector,
        private readonly ComponentsRegistry $components,
    ) {
    }

    /**
     * Построить комплексную операцию для Index-метода (список ресурсов)
     */
    public function build(...$arguments): ComplexResultDTO
    {
        $args = new IndexActionArgumentsDTO($arguments);

        // 1. Получаем схему элемента коллекции (ресурса)
        // схема одиночного ресурса
        $singleSchema = $this->model_reflector->schemaForSingleFromCollection(
            $args->model_class,
            $args->collection_resource,
        );

        $modelName = basename(str_replace('\\', '/', ltrim($args->model_class, '\\')));

        $modelRef = $this->components->getOrRegisterSchema(
            $modelName,
            fn () => $singleSchema
        );


        // 3. Query-параметры
        $parameters = [];

        if ($args->options->pagination->enable) {
            $parameters[] = Parameter::query('limit')
                ->required(false)
                ->description('Количество элементов на странице')
                ->schema(Schema::integer()->default(10));

            $parameters[] = Parameter::query('page')
                ->required(false)
                ->description('Требуемая страница')
                ->schema(Schema::integer()->default(1));
        }

        if ($args->options->orders->enable) {
            $directionEnum = array_map(static fn ($c) => $c->value, IndexActionRequestPayloadOrderDirectionEnum::cases());
            $nullPositionEnum = array_map(static fn ($c) => $c->value, IndexActionRequestPayloadOrderNullPositionEnum::cases());

            $orderItem = Schema::object()
                ->properties(
                    Schema::string('column')
                        ->description('Столбец для сортировки (формат: column или table.column)')
                        ->default('id'),
                    Schema::string('direction')
                        ->description('Направление сортировки')
                        ->enum($directionEnum)
                        ->default('asc'),
                    Schema::string('null_position')
                        ->description('Позиция NULL значений')
                        ->enum($nullPositionEnum)
                        ->default('first'),
                );

            $parameters[] = Parameter::query('order[]')
                ->required(false)
                ->description('Массив сортировок')
                ->schema(
                    Schema::create()
                        ->type('array')
                        ->items($orderItem)
                );
        }

        // 4. RequestBody (select, where, join, group_by, with, export)
        $requestBody = null;
        if ($args->options->where->enable || $args->options->select->enable || $args->options->join->enable || $args->options->group_by->enable) {
            $requestBody = $this->buildRequestBody($args);
        }
        // 5. Ответы
        $okResponse = SuccessCollectionResourceResponse::build(
            itemSchema: $modelRef,
            response_description: 'OK',
            is_pagination_enable: $args->options->pagination->enable,
            code: 200,
            message: 'OK',
        );

        $badRequest = ErrorResponse::badRequest();


        $responsesBuilder = ResponsesBuilder::create()
            ->put($okResponse)
            ->put($badRequest);

        // 6. Возвращаем готовый ComplexResultDTO
        return new ComplexResultDTO([
            'request_body' => $requestBody,
            'parameters' => $parameters,
            'responses' => $responsesBuilder,
        ]);
    }

    /**
     * Построить RequestBody со схемой фильтрации
     */
    private function buildRequestBody(IndexActionArgumentsDTO $args): RequestBody
    {
        // Перечисления
        $operatorEnum = array_map(static fn ($c) => $c->value, IndexActionRequestPayloadWhereOperatorEnum::cases());
        $valueTypeEnum = array_map(static fn ($c) => $c->value, IndexActionRequestPayloadWhereValueTypeEnum::cases());
        $returnTypeEnum = array_map(static fn ($c) => $c->value, IndexActionOptionsReturnTypeEnum::cases());
        $exportTypeEnum = array_map(static fn ($c) => $c->value, IndexActionOptionsExportExportTypeEnum::cases());
        $aggregationsEnum = array_map(static fn ($c) => $c->value, IndexActionRequestPayloadAggregationsEnum::cases());
        $joinTypeEnum = array_map(static fn ($c) => $c->value, IndexActionRequestPayloadJoinTypeEnum::cases());

        // Поля модели
        $columns = $this->model_reflector->getCollectionColumns($args->model_class, $args->collection_resource);
        $firstColumn = $columns[0] ?? 'id';
        $additions = $this->model_reflector->getCollectionAdditions($args->model_class, $args->collection_resource);

        // select[] item - SELECT с агрегациями
        $selectItem = Schema::object()
            ->properties(
                Schema::string('column')
                    ->description('Столбец для выборки (формат: column или table.column, * для всех)')
                    ->default('*'),
                Schema::string('aggregation')
                    ->description('Функция агрегации')
                    ->enum($aggregationsEnum)
                    ->nullable(true),
                Schema::string('alias')
                    ->description('Алиас для колонки в результате')
                    ->nullable(true),
            );

        // where[] item - WHERE условия (бывший filter)
        $whereItem = Schema::object()
            ->properties(
                Schema::string('type')
                    ->description('Тип объекта условия')
                    ->enum(['single', 'group'])
                    ->default('single'),
                Schema::array('group')
                    ->description('Массив условий в группе')
                    ->items(Schema::object()->properties()),
                Schema::string('column')
                    ->description('Столбец сущности (формат: column или table.column)')
                    ->enum($columns)
                    ->default($firstColumn),
                Schema::string('operator')
                    ->description('Оператор сравнения')
                    ->enum($operatorEnum)
                    ->default('='),
                Schema::string('value_type')
                    ->description('Тип значения: scalar - скалярное значение, pointer - ссылка на другую колонку')
                    ->enum($valueTypeEnum)
                    ->default('scalar'),
                Schema::string('value')
                    ->description('Значение поля. Может быть массивом значений или указателем на колонку.'),
                Schema::string('boolean')
                    ->description('Логическая операция склеивания')
                    ->enum(['and', 'or'])
                    ->default('and'),
            );

        // join[] item - JOIN
        $joinItem = Schema::object()
            ->properties(
                Schema::string('type')
                    ->description('Тип JOIN')
                    ->enum($joinTypeEnum)
                    ->default('left'),
                Schema::string('table')
                    ->description('Имя таблицы для присоединения'),
                Schema::object('on')
                    ->description('Условие ON для JOIN')
                    ->properties(
                        Schema::string('left')
                            ->description('Левая колонка (формат: table.column)'),
                        Schema::string('operator')
                            ->description('Оператор сравнения')
                            ->enum($operatorEnum)
                            ->default('='),
                        Schema::string('right')
                            ->description('Правая колонка (формат: table.column)'),
                    ),
                Schema::array('where')
                    ->description('Дополнительные WHERE условия для JOIN')
                    ->items($whereItem),
            );

        // with{}
        $withObject = Schema::object('with')
            ->description('Объект отношений или свойств, требуемых к демонстрации в ответе коллекции')
            ->properties(
                Schema::array('relationships')
                    ->description('Список отношений для выгрузки')
                    ->items(Schema::string()->enum($additions->relationships)),
                Schema::array('properties')
                    ->description('Список свойств для выгрузки')
                    ->items(Schema::string()->enum($additions->properties)),
            );

        // export{}
        $exportField = Schema::object()
            ->properties(
                Schema::string('column')
                    ->enum($columns)
                    ->default($firstColumn)
                    ->description('Столбец сущности для экспорта'),
                Schema::string('alias')
                    ->description('Название столбца в результирующей таблице')
                    ->default('Некое название'),
            );

        $exportObject = Schema::object('export')
            ->properties(
                Schema::string('file_name')->description('Имя файла при сохранении'),
                Schema::string('export_type')
                    ->enum($exportTypeEnum)
                    ->default('xlsx')
                    ->description('Тип экспортируемого файла'),
                Schema::array('fields')
                    ->description('Массив столбцов для экспорта')
                    ->items($exportField),
            );

        // Корневая схема
        $rootSchema = Schema::object()
            ->properties(
                Schema::array('select')
                    ->description('Массив колонок для SELECT с возможностью агрегаций')
                    ->items($selectItem)
                    ->example([
                        ['column' => '*'],
                        ['column' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ]),
                Schema::array('where')
                    ->description('Массив WHERE условий')
                    ->items($whereItem)
                    ->example([
                        [
                            'column' => $this->model_reflector->getScalarFieldNames($args->model_class)[0] ?? $firstColumn,
                            'value' => $firstColumn,
                        ],
                        [
                            'type' => 'group',
                            'group' => [
                                [
                                    'column' => $this->model_reflector->getScalarFieldNames($args->model_class)[0] ?? $firstColumn,
                                    'value' => $firstColumn,
                                ],
                                [
                                    'column' => $this->model_reflector->getScalarFieldNames($args->model_class)[0] ?? $firstColumn,
                                    'value' => $firstColumn,
                                    'boolean' => 'or',
                                ],
                            ],
                        ],
                    ]),
                Schema::array('join')
                    ->description('Массив JOIN операций')
                    ->items($joinItem)
                    ->example([
                        [
                            'type' => 'left',
                            'table' => 'related_table',
                            'on' => [
                                'left' => 'main_table.id',
                                'operator' => '=',
                                'right' => 'related_table.main_id',
                            ],
                        ],
                    ]),
                Schema::array('group_by')
                    ->description('Массив колонок для GROUP BY')
                    ->items(Schema::string())
                    ->example(['status', 'category_id']),
                Schema::boolean('paginate')
                    ->description('Включить пагинацию')
                    ->default(true),
                $withObject,
                Schema::string('return_type')
                    ->enum($returnTypeEnum)
                    ->default('resource')
                    ->description('Тип возвращаемого результата'),
                $exportObject,
            );

        // Собираем RequestBody билдера
        return RequestBody::create()
            ->required(false)
            ->content(MediaType::json()->schema($rootSchema));
    }
}
