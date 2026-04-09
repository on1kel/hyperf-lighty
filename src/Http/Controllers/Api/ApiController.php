<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers\Api;

use Hyperf\Contract\LengthAwarePaginatorInterface;
use Hyperf\HttpMessage\Base\Response as BaseResponse;
use Hyperf\Paginator\UrlWindow;
use Hyperf\Resource\Json\JsonResource;
use JsonException;
use On1kel\HyperfLighty\Exceptions\Http\ActionResponseException;
use On1kel\HyperfLighty\Http\Controllers\Api\DTO\ApiResponseDTO;
use On1kel\HyperfLighty\Http\Controllers\Controller;
use On1kel\HyperfLighty\Http\Resources\CollectionResource;
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
     * Развернуть JsonResource напрямую, минуя Hyperf\Resource\Response\Response::toResponse(),
     * чтобы не делать JSON round-trip, который теряет различие между пустым объектом ({}) и
     * пустым массивом ([]).
     *
     * Обрабатывает два пути:
     *   1) Пакетный CollectionResource — у него собственная пагинация через
     *      PaginatedResourceResponse, поэтому meta строим вручную (current_page/total/...).
     *   2) Любой другой JsonResource — повторяем логику Hyperf\Resource\Response\Response::wrap().
     *
     * @return array{0: mixed, 1: mixed} [$data, $meta]
     */
    protected function unwrapJsonResource(JsonResource $resource, mixed $meta): array
    {
        // 1) Пакетный CollectionResource
        if ($resource instanceof CollectionResource) {
            /** @var array<int,mixed> $items */
            $items = $resource->toArray();

            $inner = $resource->resource;
            if (\is_object($inner) && $this->looksLikePaginator($inner)) {
                $paginationMeta = $this->buildPaginationMeta($inner);

                if ($meta === null) {
                    $meta = $paginationMeta;
                } elseif (\is_array($meta)) {
                    // Пользовательские поля meta перекрывают пагинационные
                    $meta = $meta + $paginationMeta;
                }
            }

            return [$items, $meta];
        }

        // 2) Обычный JsonResource (включая SingleResource, AnonymousResourceCollection и т.п.)
        $resolved = $resource->resolve();
        $with = $resource->with();
        $additional = $resource->additional;

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
     * Эвристика «похоже на пагинатор» — повторяет CollectionResource::looksLikePaginator().
     */
    protected function looksLikePaginator(object $value): bool
    {
        foreach (['perPage', 'currentPage', 'lastPage', 'total', 'items', 'setCollection'] as $m) {
            if (! \method_exists($value, $m)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Собирает meta-блок пагинации в том же формате, что и
     * On1kel\HyperfLighty\Http\Resources\PaginatedResourceResponse::toResponse().
     *
     * @return array<string,mixed>
     */
    protected function buildPaginationMeta(object $p): array
    {
        /** @var int|null $total */
        $total = \method_exists($p, 'total') ? $this->toIntOrNull($p->total()) : null;
        /** @var int|null $perPage */
        $perPage = \method_exists($p, 'perPage') ? $this->toIntOrNull($p->perPage()) : null;
        /** @var int|null $currentPage */
        $currentPage = \method_exists($p, 'currentPage') ? $this->toIntOrNull($p->currentPage()) : null;
        /** @var int|null $lastPage */
        $lastPage = \method_exists($p, 'lastPage') ? $this->toIntOrNull($p->lastPage()) : null;

        $from = null;
        if (\method_exists($p, 'firstItem')) {
            $from = $this->toIntOrNull($p->firstItem());
        } elseif ($currentPage !== null && $perPage !== null && $total !== null) {
            $from = $total === 0 ? 0 : (($currentPage - 1) * $perPage) + 1;
        }

        $to = null;
        if (\method_exists($p, 'lastItem')) {
            $to = $this->toIntOrNull($p->lastItem());
        } elseif ($currentPage !== null && $perPage !== null && $total !== null) {
            $to = $total === 0 ? 0 : \min($currentPage * $perPage, $total);
        }

        $links = $this->buildPaginationLinks($p, $currentPage, $lastPage);

        return [
            'current_page' => $currentPage,
            'from' => $from,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'to' => $to,
            'total' => $total,
            'links' => $links,
        ];
    }

    /**
     * Собирает массив links в стиле Laravel: « Previous | первые N | … | средние | … | последние | Next ».
     * Для LengthAwarePaginator использует Hyperf\Paginator\UrlWindow (тот же алгоритм, что и в Laravel).
     * Для остальных пагинаторов отдаёт минимальный набор: только Previous и Next.
     *
     * @return array<int, array{url: string|null, label: string, active: bool}>
     */
    protected function buildPaginationLinks(object $p, ?int $currentPage, ?int $lastPage): array
    {
        $links = [];

        /** @var string|null $prevUrl */
        $prevUrl = \method_exists($p, 'previousPageUrl') ? $p->previousPageUrl() : null;
        $links[] = [
            'url' => $prevUrl,
            'label' => '&laquo; Previous',
            'active' => false,
        ];

        // Нумерация страниц через UrlWindow — только если паджинатор length-aware.
        if ($p instanceof LengthAwarePaginatorInterface && $lastPage !== null && $lastPage > 0) {
            $window = UrlWindow::make($p);

            // Собираем элементы в том же порядке, что и Laravel\LengthAwarePaginator::elements():
            // first → '...' → slider → '...' → last (с фильтрацией пустых).
            $elements = [];
            if (\is_array($window['first']) && $window['first'] !== []) {
                $elements[] = $window['first'];
            }
            if (\is_array($window['slider']) && $window['slider'] !== []) {
                $elements[] = '...';
                $elements[] = $window['slider'];
            }
            if (\is_array($window['last']) && $window['last'] !== []) {
                $elements[] = '...';
                $elements[] = $window['last'];
            }

            foreach ($elements as $element) {
                if ($element === '...') {
                    $links[] = [
                        'url' => null,
                        'label' => '...',
                        'active' => false,
                    ];

                    continue;
                }

                /** @var array<int,string> $element */
                foreach ($element as $page => $url) {
                    $links[] = [
                        'url' => $url,
                        'label' => (string) $page,
                        'active' => ($currentPage === $page),
                    ];
                }
            }
        }

        /** @var string|null $nextUrl */
        $nextUrl = \method_exists($p, 'nextPageUrl') ? $p->nextPageUrl() : null;
        $links[] = [
            'url' => $nextUrl,
            'label' => 'Next &raquo;',
            'active' => false,
        ];

        return $links;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_numeric($value)) {
            return (int) $value;
        }

        return null;
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
