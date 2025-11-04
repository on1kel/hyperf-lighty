<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes;

use On1kel\HyperfFlyDocs\Generator\Contracts\ComplexFactoryInterface;
use On1kel\HyperfFlyDocs\Generator\DTO\ComplexResultDTO;
use On1kel\HyperfLighty\OpenApi\Complexes\DestroyAction\DestroyActionArgumentsDTO;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\ErrorResponse;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\SuccessResponse;
use On1kel\OAS\Builder\Responses\Responses as ResponsesBuilder;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;

final class DestroyActionComplex implements ComplexFactoryInterface
{
    /**
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    public function build(...$arguments): ComplexResultDTO
    {
        $args = new DestroyActionArgumentsDTO($arguments);

        $okResponse = SuccessResponse::build(
            data: 'ok',
            response_description: 'OK',
            data_type: 'string',
        );

        $badRequest = ErrorResponse::badRequest();


        $responses = ResponsesBuilder::create()
            ->put($okResponse)
            ->put($badRequest);

        return new ComplexResultDTO(
            responses: $responses,
        );
    }
}
