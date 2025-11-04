<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\Responses;

use On1kel\OAS\Builder\Responses\Response;
use On1kel\OAS\Builder\Schema\Schema;
use On1kel\OAS\Core\Model\Reference;

final class SuccessSingleResourceResponse
{
    public static function build(
        Schema|Reference|string|array $data,
        string $response_description = 'Успешный ответ',
        int $code = 200,
        string $message = 'OK',
        string $contentType = 'application/json',
    ): Response {
        return SuccessResponse::build(
            data: $data,
            additional_properties: [],
            response_description: $response_description,
            data_type: 'object',
            code: $code,
            message: $message,
            contentType: $contentType,
        );
    }
}
