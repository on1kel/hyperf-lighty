<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\Responses;

use On1kel\OAS\Builder\Responses\Response;
use On1kel\OAS\Builder\Schema\Schema;
use On1kel\OAS\Core\Model\Reference;

final class SuccessCollectionResourceResponse
{
    public static function build(
        Schema|Reference|string $itemSchema,
        string $response_description = 'Успешный ответ',
        bool $is_pagination_enable = true,
        int $code = 200,
        string $message = 'OK',
        string $contentType = 'application/json',
    ): Response {
        $additional = [];

        if ($is_pagination_enable) {
            $additional['meta'] = self::metaSchema();
        }

        return SuccessResponse::build(
            data: $itemSchema,
            additional_properties: $additional,
            response_description: $response_description,
            data_type: 'array',
            code: $code,
            message: $message,
            contentType: $contentType,
        );
    }

    /**
     * Схема meta пагинации:
     * {
     *   current_page: int,
     *   from: int,
     *   last_page: int,
     *   per_page: int,
     *   to: int,
     *   total: int,
     *   links: [ { url, label, active }, ... ]
     * }
     */
    private static function metaSchema(): Schema
    {
        $currentPage = Schema::integer('current_page')
            ->description('Текущая страница')
            ->default(1);

        $from = Schema::integer('from')
            ->description('Минимальный порядковый номер элемента коллекции на странице')
            ->default(1);

        $lastPage = Schema::integer('last_page')
            ->description('Последняя страница')
            ->default(1);

        $perPage = Schema::integer('per_page')
            ->description('Количество элементов на странице')
            ->default(10);

        $to = Schema::integer('to')
            ->description('Максимальный порядковый номер элемента коллекции на странице')
            ->default(10);

        $total = Schema::integer('total')
            ->description('Общее количество элементов коллекции')
            ->default(10);

        $linkItemSchema = Schema::object()
            ->properties(
                Schema::string('url')
                    ->description('Ссылка на страницу')
                    ->default('http://route?page=1')
                    ->nullable(true),
                Schema::string('label')
                    ->description('Метка страницы')
                    ->default('« Previous'),
                Schema::boolean('active')
                    ->description('Доступность страницы')
                    ->default(true),
            );

        $linksSchema = Schema::array('links')->items($linkItemSchema);

        return Schema::object()
            ->properties(
                $currentPage,
                $from,
                $lastPage,
                $perPage,
                $to,
                $total,
                $linksSchema,
            );
    }
}
