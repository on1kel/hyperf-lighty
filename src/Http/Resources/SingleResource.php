<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Resources;

use Hyperf\HttpServer\Contract\RequestInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

/**
 * Единичный ресурс для Hyperf + hyperf/resource.
 */
abstract class SingleResource extends JsonResource
{
    /**
     * @param mixed $resource
     * @param mixed $is_parent
     * @param bool  $ignore_properties_if_parents
     */
    public function __construct(mixed $resource, mixed $is_parent = false, bool $ignore_properties_if_parents = false)
    {
        $this->is_parent = \is_bool($is_parent) ? $is_parent : false;
        $this->ignore_properties_if_parents = $ignore_properties_if_parents;

        parent::__construct($resource);
    }

    /**
     * Кастомизация исходящего ответа ресурса (аналог Laravel withResponse).
     * Типы не жёсткие, чтобы работать и со Swow ResponsePlusInterface, и с PSR-7.
     *
     * @param RequestInterface|mixed         $request
     * @param ResponsePlusInterface|mixed    $response
     */
    public function withResponse($request, $response): void
    {
        // пример: добавить кастомный заголовок, если доступен mutator
        if (\is_object($response) && \method_exists($response, 'setHeader')) {
            $response->setHeader('X-Value', 'True');

            return;
        }

        // фолбэк для PSR-7 (иммутабельные withHeader): ничего не делаем
        // — т.к. объект должен быть заменён снаружи, если понадобится.
    }

    /**
     * Возвращает логируемые атрибуты при наличии пар полей.
     * (замена merge()/mergeWhen() без использования MergeValue)
     *
     * @return array<string, mixed>
     */
    public function withLoggingableAttributes(): array
    {
        $out = [];
        $res = $this->resource;

        // Гарантируем, что работаем с объектом
        if (! is_object($res)) {
            return $out;
        }

        // created
        if (! empty($res->created_at) && ! empty($res->created_by)) {
            $out['created_at'] = $res->created_at;
            $out['created_by'] = $res->created_by;
        }

        // updated
        if (! empty($res->updated_at) && ! empty($res->updated_by)) {
            $out['updated_at'] = $res->updated_at;
            $out['updated_by'] = $res->updated_by;
        }

        // deleted
        if (! empty($res->deleted_at) && ! empty($res->deleted_by)) {
            $out['deleted_at'] = $res->deleted_at;
            $out['deleted_by'] = $res->deleted_by;
        }

        return $out;
    }
}
