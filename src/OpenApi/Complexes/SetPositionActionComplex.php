<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes;

use On1kel\HyperfFlyDocs\Generator\Contracts\ComplexFactoryInterface;
use On1kel\HyperfFlyDocs\Generator\DTO\ComplexResultDTO;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\ErrorResponse;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\SuccessResponse;
use On1kel\HyperfLighty\OpenApi\Complexes\SetPositionAction\SetPositionActionArgumentsDTO;
use On1kel\OAS\Builder\Bodies\RequestBody;
use On1kel\OAS\Builder\Media\MediaType;
use On1kel\OAS\Builder\Responses\Responses;
use On1kel\OAS\Builder\Schema\Schema;

final class SetPositionActionComplex implements ComplexFactoryInterface
{
    public function build(...$arguments): ComplexResultDTO
    {
        $args = new SetPositionActionArgumentsDTO($arguments);

        /**
         * Схема тела запроса:
         *
         * {
         *   "ids": string[]  // обязательный массив хотя бы из 1 элемента
         * }
         */
        $idsArraySchema = Schema::array('ids')
            ->description('Идентификаторы сущностей, которые требуется позиционировать')
            ->items(
                Schema::string()
            )
            ->minItems(1)
            ->uniqueItems();

        $requestSchema = Schema::object()
            ->properties(
                $idsArraySchema,
            )
            ->required('ids');

        $requestBody = RequestBody::create()
            ->required()
            ->content(MediaType::json()->schema($requestSchema));

        $okResponse = SuccessResponse::build(
            data: 'ok',
            response_description: 'OK',
            data_type: 'string',
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
