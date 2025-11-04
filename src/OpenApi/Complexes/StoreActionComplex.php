<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes;

use On1kel\HyperfFlyDocs\Generator\Contracts\ComplexFactoryInterface;
use On1kel\HyperfFlyDocs\Generator\DTO\ComplexResultDTO;
use On1kel\HyperfFlyDocs\Generator\Registry\ComponentsRegistry;
use On1kel\HyperfLighty\OpenApi\Complexes\Reflector\ModelReflector;
use On1kel\HyperfLighty\OpenApi\Complexes\Reflector\RequestReflector;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\ErrorResponse;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\SuccessSingleResourceResponse;
use On1kel\HyperfLighty\OpenApi\Complexes\StoreAction\StoreActionArgumentsDTO;
use On1kel\OAS\Builder\Responses\Responses;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;

final class StoreActionComplex implements ComplexFactoryInterface
{
    public function __construct(
        private readonly ModelReflector $model_reflector,
        private readonly RequestReflector $request_reflector,
        private readonly ComponentsRegistry $components,
    ) {
    }

    /**
     * @param ...$arguments
     * @return ComplexResultDTO
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    public function build(...$arguments): ComplexResultDTO
    {
        $args = new StoreActionArgumentsDTO($arguments);

        $requestBody = null;
        if ($args->validation_request) {
            $requestBody = $this->request_reflector->reflect($args->validation_request);
        }

        $modelRef = $this->components->getOrRegisterSchema(
            $args->model_class,
            fn () => $this->model_reflector->getSchemaForSingle(
                $args->model_class,
                $args->single_resource
            )
        );

        $okResponse = SuccessSingleResourceResponse::build(
            data: $modelRef,
        );

        $badRequest = ErrorResponse::badRequest();

        $responses = Responses::create()
            ->put($okResponse)
            ->put($badRequest);

        return new ComplexResultDTO([
            'request_body' => $requestBody,
            'responses' => $responses,
        ]);
    }
}
