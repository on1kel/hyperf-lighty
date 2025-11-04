<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api;

use Hyperf\HttpMessage\Base\Response as BaseResponse;
use JsonException;
use On1kel\HyperfLighty\Exceptions\Http\ActionResponseException;
use On1kel\HyperfLighty\Http\Controllers\Api\DTO\ApiResponseDTO;
// ваш базовый ресурс
// ресурс из hyperf/resource
use On1kel\HyperfLighty\Http\Controllers\Controller;
use Psr\Http\Message\ResponseInterface;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;
use Throwable;

abstract class ApiController extends Controller
{
    /**
     * Возвращает список FormRequest-классов валидации для контроллера
     * или для конкретного метода ($method), если он указан.
     *
     * @throws JsonException
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    public function getValidations(?string $method = null): ResponseInterface
    {
        $validations = get_controller_validation_request_classes($this);

        if ($method === null) {
            return $this->respondDto(
                $this->buildActionResponseDTO(
                    data: $validations,
                )
            );
        }

        if (\array_key_exists($method, $validations)) {
            return $this->respondDto(
                $this->buildActionResponseDTO(
                    data: [
                        $method => $validations[$method],
                    ],
                )
            );
        }

        return $this->respondDto(
            $this->buildActionResponseDTO(
                data: null,
            )
        );
    }

    /**
     * Стандартный 404-ответ.
     *
     * @throws UnknownProperties
     * @throws JsonException
     * @throws ReflectionException
     */
    public function buildNotFoundResponse(): ResponseInterface
    {
        return $this->respondDto(
            $this->buildActionResponseDTO(
                data: 'Not Found',
                status: 'error',
                code: 404,
            )
        );
    }

    /**
     * Собрать DTO ответа с учётом статуса/кода/заголовков.
     *
     * @param  mixed                   $data
     * @param  mixed|null              $meta
     * @param  'success'|'error'       $status
     * @param  int                     $code
     * @param  string|null             $message
     * @param  array<string,string>    $headers
     *
     * @throws UnknownProperties
     * @throws JsonException
     * @throws ReflectionException
     */
    public function buildActionResponseDTO(
        mixed $data,
        mixed $meta = null,
        string $status = 'success',
        int $code = 200,
        ?string $message = null,
        array $headers = ['Content-Type' => 'application/json'],
    ): ApiResponseDTO {
        if ($code === 200 && $data instanceof Throwable) {
            $code = 400;
        }

        if (! \array_key_exists('Content-Type', $headers)) {
            $headers['Content-Type'] = 'application/json';
        }

        // Если передан ресурс (наш или hyperf), разворачиваем его ответ
        if (\is_object($data) && \method_exists($data, 'toResponse')) {
            /** @var \Psr\Http\Message\ResponseInterface $tmpResponse */
            $tmpResponse = $data->toResponse();
            $raw = (string) $tmpResponse->getBody();

            /** @var array<string,mixed>|null $tmpData */
            $tmpData = \json_decode($raw, true);
            if (\is_array($tmpData)) {
                // 1) вытащим meta, если есть
                if ($meta === null && \array_key_exists('meta', $tmpData)) {
                    $meta = $tmpData['meta'];
                }

                // 2) вытащим data, если есть
                if (\array_key_exists('data', $tmpData)) {
                    $data = $tmpData['data'];
                } else {
                    // ресурс вернул «плоский» массив без ключа data
                    $data = $tmpData;
                }
            }
        }

        // Доп. страховка от двойной обёртки: если в $data остался ровно один ключ `data` — распакуем
        if (\is_array($data) && \count($data) === 1 && \array_key_exists('data', $data)) {
            $data = $data['data'];
        }

        $response = [
            'status' => $status,
            'code' => $code,
            'message' => $message ?: BaseResponse::getReasonPhraseByCode($code),
            'headers' => $headers,
            'meta' => $meta,
        ];

        if ($status === 'success') {
            $response['data'] = $data;
        } elseif ($status === 'error' || $data instanceof Throwable) {
            $response['error'] = $data;
        } else {
            throw new ActionResponseException('Unknown status code');
        }

        return new ApiResponseDTO($response);
    }

    /**
     * Преобразовать ApiResponseDTO в HTTP-ответ Hyperf.
     *
     * @throws JsonException
     */
    protected function respondDto(ApiResponseDTO $dto): ResponseInterface
    {
        // ApiResponseDTO должен уметь отдавать «плоский» массив ответа.
        $payload = $dto->buildResponseContent();

        // Код и заголовки берём из dto
        $code = \is_int($payload['code'] ?? null) ? (int) $payload['code'] : 200;
        $headers = \is_array($payload['headers'] ?? null) ? $payload['headers'] : [];

        $resp = $this->response()->json($payload)->withStatus($code);

        foreach ($headers as $name => $value) {
            $resp = $resp->withHeader((string) $name, (string) $value);
        }

        return $resp;
    }
}
