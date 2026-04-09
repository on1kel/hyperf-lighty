<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api;

use Hyperf\Collection\Arr;
use Hyperf\HttpMessage\Base\Response as BaseResponse;
use Hyperf\Paginator\AbstractPaginator;
use Hyperf\Resource\Json\JsonResource;
use Hyperf\Resource\Json\ResourceCollection;
use JsonException;
use On1kel\HyperfLighty\Exceptions\Http\ActionResponseException;
use On1kel\HyperfLighty\Http\Controllers\Api\DTO\ApiResponseDTO;
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

        // Если передан ресурс hyperf/resource — разворачиваем его без JSON round-trip,
        // иначе пустые объекты (stdClass) превращаются в [] на шаге json_decode(..., true).
        if ($data instanceof JsonResource) {
            [$data, $meta] = $this->unwrapJsonResource($data, $meta);
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
     * Развернуть JsonResource напрямую через resolve()/with()/additional, минуя
     * Hyperf\Resource\Response\Response::toResponse(), чтобы не делать JSON round-trip,
     * который теряет различие между пустым объектом ({}) и пустым массивом ([]).
     *
     * Логика повторяет Hyperf\Resource\Response\Response::wrap() и
     * Hyperf\Resource\Response\PaginatedResponse::paginationInformation().
     *
     * @return array{0: mixed, 1: mixed} [$data, $meta]
     */
    protected function unwrapJsonResource(JsonResource $resource, mixed $meta): array
    {
        $resolved = $resource->resolve();
        $with = $resource->with();
        $additional = $resource->additional;

        // Пагинация: повторяем PaginatedResponse::paginationInformation()
        if ($resource instanceof ResourceCollection
            && $resource->resource instanceof AbstractPaginator
            && \method_exists($resource->resource, 'toArray')
        ) {
            /** @var array<string,mixed> $paginated */
            $paginated = $resource->resource->toArray();
            $pagination = [
                'links' => [
                    'first' => $paginated['first_page_url'] ?? null,
                    'last' => $paginated['last_page_url'] ?? null,
                    'prev' => $paginated['prev_page_url'] ?? null,
                    'next' => $paginated['next_page_url'] ?? null,
                ],
                'meta' => Arr::except($paginated, [
                    'data',
                    'first_page_url',
                    'last_page_url',
                    'prev_page_url',
                    'next_page_url',
                ]),
            ];
            $with = \array_merge_recursive($pagination, $with);
        }

        // Повторяем Response::wrap()
        $wrapper = $resource->wrap;
        if ($wrapper !== null && ! \array_key_exists($wrapper, $resolved)) {
            $resolved = [$wrapper => $resolved];
        } elseif ((! empty($with) || ! empty($additional))
            && ($wrapper === null || ! \array_key_exists($wrapper, $resolved))
        ) {
            $resolved = [($wrapper ?? 'data') => $resolved];
        }

        $merged = \array_merge_recursive($resolved, $with, $additional);

        if ($meta === null && \array_key_exists('meta', $merged)) {
            $meta = $merged['meta'];
            unset($merged['meta']);
        }

        $data = \array_key_exists('data', $merged) ? $merged['data'] : $merged;

        return [$data, $meta];
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
