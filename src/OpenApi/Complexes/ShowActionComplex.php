<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes;

use On1kel\HyperfFlyDocs\Generator\Contracts\ComplexFactoryInterface;
use On1kel\HyperfFlyDocs\Generator\DTO\ComplexResultDTO;
use On1kel\HyperfFlyDocs\Generator\Registry\ComponentsRegistry;
use On1kel\HyperfLighty\OpenApi\Complexes\Reflector\ModelReflector;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\ErrorResponse;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\SuccessSingleResourceResponse;
use On1kel\HyperfLighty\OpenApi\Complexes\ShowAction\ShowActionArgumentsDTO;
use On1kel\OAS\Builder\Responses\Response;
use On1kel\OAS\Builder\Responses\Responses;
use On1kel\OAS\Builder\Schema\Schema;

final class ShowActionComplex implements ComplexFactoryInterface
{
    public function __construct(
        private readonly ModelReflector $model_reflector,
        private readonly ComponentsRegistry $components,
    ) {
    }

    /**
     * Построить комплексную спецификацию для "show" (получить один ресурс).
     *
     * Возвращает ComplexResultDTO:
     *  - без тела запроса,
     *  - без дополнительных query-параметров,
     *  - с ответами 200 и 400.
     *
     * path-параметры (например, {id}) ты можешь навесить на уровне роутинга/operation builder'а,
     * так же как делается в остальном пайплайне.
     */
    public function build(...$arguments): ComplexResultDTO
    {
        $args = new ShowActionArgumentsDTO($arguments);

        /**
         * 1) Схема одиночного ресурса
         *
         * Ожидаем, что getSchemaForSingle() вернёт Schema (builder),
         * описывающую сам ресурс (Object schema, не массив).
         */
        $singleSchema = $this->model_reflector->getSchemaForSingle(
            $args->model_class,
            $args->single_resource
        );

        /**
         * 2) Регистрируем компонент модели и получаем $ref.
         *
         * Имя компонента берём из имени класса модели,
         * например App\Models\Post -> "Post".
         */
        $modelRef = $this->components->getOrRegisterSchema(
            $args->model_class,
            fn () => $singleSchema,
        );

        /**
         * 3) Ответы операции.
         *
         * - 200: успешный ответ с одним ресурсом:
         *   {
         *     status: "success",
         *     code: 200,
         *     message: "OK",
         *     data: { ...resource... }
         *   }
         *
         *   Здесь в data пойдёт $modelRef (Schema::ref('#/components/schemas/<ModelName>'))
         *
         * - 400: badRequest() из ErrorResponse
         */
        $okResponse = SuccessSingleResourceResponse::build(
            data: $modelRef,
        );

        $badRequest = ErrorResponse::badRequest();


        $responses = Responses::create()
            ->put($okResponse)
            ->put($badRequest);

        /**
         * 4) Собираем ComplexResultDTO.
         *
         * request_body => null (show обычно не принимает тело запроса),
         * parameters   => []   (параметр path {id} ты можешь навешивать отдельным слоем),
         * responses    => мапа кодов в Response.
         */
        return new ComplexResultDTO([
            'responses' => $responses,
        ]);
    }
}
