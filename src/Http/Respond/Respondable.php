<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Respond;

use Closure;
use Hyperf\HttpMessage\Stream\SwooleStream;
use JsonException;
use On1kel\HyperfLighty\Http\Controllers\Api\DTO\ApiResponseDTO;
use Swow\Psr7\Message\ResponsePlusInterface;

trait Respondable
{
    /**
     * @param ApiResponseDTO $action_response
     * @param ResponsePlusInterface $response
     * @param Closure|null $closure
     * @param int $json_flags
     * @return ResponsePlusInterface
     */
    /**
     * Возвращает JSON-ответ через Swow ResponsePlusInterface.
     *
     * @throws JsonException
     */
    public function respond(
        ApiResponseDTO $action_response,
        ResponsePlusInterface $response,
        ?Closure $closure = null,
        int $json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ): ResponsePlusInterface {
        $content = json_encode($action_response->buildResponseContent(), $json_flags);

        if ($content === false) {
            throw new \RuntimeException('JSON encode error: ' . json_last_error_msg());
        }

        $status = $this->normalizeStatusCode($action_response->code);
        $headers = $action_response->headers ?: [];

        // Устанавливаем код, заголовки и тело
        $response->setStatus($status);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        foreach ($headers as $name => $value) {
            $response->setHeader((string) $name, (string) $value);
        }

        $response->setBody(new SwooleStream($content));

        // Позволяем колбэку модифицировать ответ при необходимости
        if ($closure !== null) {
            $tmp = $closure($response);
            if ($tmp instanceof ResponsePlusInterface) {
                $response = $tmp;
            }
        }

        return $response;
    }

    protected function normalizeStatusCode(mixed $status_code): int
    {
        $code = is_numeric($status_code) ? (int) $status_code : 0;

        return ($code >= 100 && $code <= 599) ? $code : 400;
    }
}
