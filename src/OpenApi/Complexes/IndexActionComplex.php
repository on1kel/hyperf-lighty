<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes;

use On1kel\HyperfFlyDocs\Generator\Contracts\ComplexFactoryInterface;
use On1kel\HyperfFlyDocs\Generator\DTO\ComplexResultDTO;
use On1kel\HyperfFlyDocs\Generator\Registry\ComponentsRegistry;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsExportExportTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Option\IndexActionOptionsReturnTypeEnum;
use On1kel\HyperfLighty\Http\Controllers\Api\CRUD\DTO\IndexAction\Payload\IndexActionRequestPayloadFilterOperatorEnum;
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
            $parameters[] = Parameter::query('order[]')
                ->required(false)
                ->description('Массив сортировок')
                ->schema(
                    Schema::create()
                        ->type('array')
                        ->items(Schema::string())
                        ->default(['-id'])
                );
        }

        // 4. RequestBody (фильтры, with, export)
        $requestBody = null;
        if ($args->options->filters->enable) {
            $requestBody = $this->buildFiltersRequestBody($args);
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
    private function buildFiltersRequestBody(IndexActionArgumentsDTO $args): RequestBody
    {
        // Перечисления
        $operatorEnum = array_map(static fn ($c) => $c->value, IndexActionRequestPayloadFilterOperatorEnum::cases());
        $returnTypeEnum = array_map(static fn ($c) => $c->value, IndexActionOptionsReturnTypeEnum::cases());
        $exportTypeEnum = array_map(static fn ($c) => $c->value, IndexActionOptionsExportExportTypeEnum::cases());

        // Поля модели
        $columns = $this->model_reflector->getCollectionColumns($args->model_class, $args->collection_resource);
        $firstColumn = $columns[0] ?? 'id';
        $additions = $this->model_reflector->getCollectionAdditions($args->model_class, $args->collection_resource);

        // filter[] item
        $filterItem = Schema::object()
            ->properties(
                Schema::string('type')
                    ->description('Тип объекта фильтра')
                    ->enum(['single', 'group'])
                    ->default('single'),
                Schema::array('group')
                    ->description('Массив фильтров в группе')
                    ->items(Schema::object()->properties()),
                Schema::string('column')
                    ->description('Столбец сущности, по которому осуществляется поиск')
                    ->enum($columns)
                    ->default($firstColumn),
                Schema::string('operator')
                    ->description('Оператор сравнения')
                    ->enum($operatorEnum)
                    ->default('='),
                Schema::string('boolean')
                    ->description('Логическая операция склеивания')
                    ->enum(['and', 'or'])
                    ->default('and'),
                Schema::string('value')
                    ->description('Значение поля. Может быть массивом значений.'),
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
                Schema::array('filter')
                    ->description('Массив фильтров')
                    ->items($filterItem)
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
