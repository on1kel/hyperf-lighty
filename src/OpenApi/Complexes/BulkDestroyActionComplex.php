<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes;

use On1kel\HyperfFlyDocs\Generator\Contracts\ComplexFactoryInterface;
use On1kel\HyperfFlyDocs\Generator\DTO\ComplexResultDTO;
use On1kel\HyperfLighty\OpenApi\Complexes\BulkDestroyAction\BulkDestroyActionArgumentsDTO;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\ErrorResponse;
use On1kel\HyperfLighty\OpenApi\Complexes\Responses\SuccessResponse;
use On1kel\OAS\Builder\Bodies\RequestBody;
use On1kel\OAS\Builder\Media\MediaType;
use On1kel\OAS\Builder\Responses\Responses as ResponsesBuilder;
use On1kel\OAS\Builder\Schema\Schema;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;

final class BulkDestroyActionComplex implements ComplexFactoryInterface
{
    /**
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    public function build(...$arguments): ComplexResultDTO
    {
        $args = new BulkDestroyActionArgumentsDTO($arguments);


        $requestBody = RequestBody::create()->required()->content(
            MediaType::json()->schema(
                Schema::object()->required('ids')->properties(
                    Schema::array('ids')
                        ->description('Идентификаторы сущностей, которые требуется удалить')
                        ->items(
                            Schema::string()
                        )
                        ->minItems(1)
                        ->uniqueItems()
                )
            )
        );

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
            request_body: $requestBody,
            responses: $responses,
        );
    }
}
