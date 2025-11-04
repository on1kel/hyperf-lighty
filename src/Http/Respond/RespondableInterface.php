<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Respond;

use Closure;
use On1kel\HyperfLighty\Http\Controllers\Api\DTO\ApiResponseDTO;
use Swow\Psr7\Message\ResponsePlusInterface;

interface RespondableInterface
{
    public function respond(
        ApiResponseDTO $action_response,
        ResponsePlusInterface $response,
        ?Closure $closure = null,
        int $json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ): ResponsePlusInterface;
}
