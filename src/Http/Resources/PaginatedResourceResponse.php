<?php

namespace On1kel\HyperfLighty\Http\Resources;

use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;

use function method_exists;

use Psr\Http\Message\ResponseInterface;

final class PaginatedResourceResponse
{
    /** @var array<int|string, mixed> */
    private array $data;

    /** @var object пагинатороподобный объект */
    private object $paginator;

    /**
     * @param array<int|string, mixed> $data Готовые данные (обычно ['data' => [...]] и т.п.)
     * @param object $paginator Объект с методами total()/perPage()/currentPage()/lastPage()/url()/...
     */
    public function __construct(array $data, object $paginator)
    {
        $this->data = $data;
        $this->paginator = $paginator;
    }

    public function toResponse(): ResponseInterface
    {
        $p = $this->paginator;

        // Базовые метрики
        $total = method_exists($p, 'total') ? $p->total() : null;
        $perPage = method_exists($p, 'perPage') ? $p->perPage() : null;
        $currentPage = method_exists($p, 'currentPage') ? $p->currentPage() : null;
        $lastPage = method_exists($p, 'lastPage') ? $p->lastPage() : null;

        // from / to (сначала пробуем методы, затем формулы)
        $from = null;
        if (method_exists($p, 'firstItem')) {
            $from = $p->firstItem();
        } elseif ($currentPage !== null && $perPage !== null && $total !== null) {
            $from = $total === 0 ? 0 : (($currentPage - 1) * $perPage) + 1;
        }

        $to = null;
        if (method_exists($p, 'lastItem')) {
            $to = $p->lastItem();
        } elseif ($currentPage !== null && $perPage !== null && $total !== null) {
            $to = $total === 0 ? 0 : min($currentPage * $perPage, $total);
        }

        // Ссылки в Laravel-стиле: « Previous | 1..N | Next »
        $links = [];
        $hasUrl = method_exists($p, 'url');
        $hasPrev = method_exists($p, 'previousPageUrl');
        $hasNext = method_exists($p, 'nextPageUrl');

        // Previous
        $links[] = [
            'url' => $hasPrev ? $p->previousPageUrl() : null,
            'label' => '&laquo; Previous',
            'active' => false,
        ];

        // Нумерация 1..lastPage (если знаем lastPage)
        if (is_int($lastPage) && $lastPage > 0) {
            for ($i = 1; $i <= $lastPage; $i++) {
                $links[] = [
                    'url' => $hasUrl ? $p->url($i) : null,
                    'label' => (string)$i,
                    'active' => ($currentPage === $i),
                ];
            }
        }

        // Next
        $links[] = [
            'url' => $hasNext ? $p->nextPageUrl() : null,
            'label' => 'Next &raquo;',
            'active' => false,
        ];

        // Собираем meta ровно как ты хочешь
        $meta = [
            'current_page' => $currentPage,
            'from' => $from,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'to' => $to,
            'total' => $total,
            'links' => $links,
        ];

        // Базовый payload, который пришёл из CollectionResource
        $payload = $this->data;

        // Если вдруг кто-то уже положил meta — аккуратно поверх
        $metaBase = $payload['meta'] ?? [];
        if (! is_array($metaBase)) {
            $metaBase = [];
        }
        $payload['meta'] = $metaBase + $meta;

        // ВАЖНО: ссылки кладём ВНУТРЬ meta, а не на верхний уровень
        // (Раньше ты делал $payload['links'] = ... — убираем это).

        /** @var HttpResponse $response */
        $response = ApplicationContext::getContainer()->get(HttpResponse::class);

        return $response->json($payload);
    }
}
