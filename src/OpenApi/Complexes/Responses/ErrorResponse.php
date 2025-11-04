<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\Responses;

use On1kel\OAS\Builder\Media\MediaType;
use On1kel\OAS\Builder\Responses\Response;
use On1kel\OAS\Builder\Schema\Schema;
use RuntimeException;

final class ErrorResponse
{
    /**
     * Построить универсальный error-ответ:
     * {
     *   status:  "error",
     *   code:    <int>,
     *   message: "…",
     *   error:   <object|array|string>
     * }
     *
     * $error:
     *  - error_type=object:
     *      - Schema (готовая схема объекта)
     *      - array<string,Schema> (карта полей объекта)
     *      - string (будет завернута в { message: <string> })
     *  - error_type=array:
     *      - Schema (схема элемента массива)
     *      - array<string,Schema> (элемент массива = объект с этими полями)
     *      - string (массив строк)
     *  - error_type=string:
     *      - string|null (просто строковое поле)
     *      - другое → будет сведено к строке "[error payload]"
     */
    public static function build(
        Schema|array|string|null $error = null,
        string $response_description = 'Ответ с ошибкой',
        string $error_type = 'object',            // object|array|string
        int $code = 400,
        string $message = 'Bad Request',
        string $contentType = 'application/json',
    ): Response {
        $available = ['object', 'array', 'string'];
        if (! in_array($error_type, $available, true)) {
            throw new RuntimeException(sprintf(
                'Неверный тип ошибки (%s). Возможные типы: %s',
                $error_type,
                implode(',', $available),
            ));
        }

        /**
         * 1. Если ошибка не передана — создаём дефолт
         */
        if ($error === null) {
            // По умолчанию считаем, что это object
            $error = Schema::object()
                ->description('Ошибка может быть представлена различными способами.')
                ->properties(
                    Schema::string('example')->default('error')
                );

            $error_type = 'object';
        }

        /**
         * 2. Строим поле "error" в зависимости от $error_type
         */
        $errorFieldSchema = match ($error_type) {
            'object' => self::buildObjectError($error),
            'array' => self::buildArrayError($error),
            'string' => self::buildStringError($error),
        };

        /**
         * 3. Обёртка-ответ
         */
        $envelope = Schema::object()
            ->properties(
                Schema::string('status')
                    ->description('Статус запроса')
                    ->default('error'),
                Schema::integer('code')
                    ->description('Код запроса')
                    ->default($code),
                Schema::string('message')
                    ->description('Сообщение запроса')
                    ->default($message),
                $errorFieldSchema->named('error')
            );
        ;

        /**
         * 4. Оборачиваем в Response билдера
         */
        return Response::code($code)
            ->description($response_description)
            ->contentMap([
                $contentType => MediaType::of($contentType)->schema($envelope),
            ]);
    }

    /**
     * Построение схемы для случая error_type = 'object'
     *
     * Варианты:
     *  - Schema: используем как есть
     *  - array<string,Schema>: строим объект с этими полями
     *  - string: строим { message: <string> }
     */
    private static function buildObjectError(Schema|array|string|null $err): Schema
    {
        if ($err instanceof Schema) {
            return $err;
        }

        if (is_array($err)) {
            // Считаем, что это карта полей объекта: [ fieldName => Schema ]
            /** @var Schema $obj */
            $obj = Schema::object();
            foreach ($err as $fieldName => $fieldSchema) {
                if (! $fieldSchema instanceof Schema) {
                    throw new RuntimeException(
                        'Для object-ошибки значения массива должны быть Schema.'
                    );
                }

                $obj = $obj->propertiesNamed([(string)$fieldName => $fieldSchema]);
            }

            return $obj;
        }

        // Иначе строка → { message: <string> }
        return Schema::object()
            ->properties(
                Schema::object('example')->default((string)$err)
            );
    }

    /**
     * Построение схемы для случая error_type = 'array'
     *
     * Варианты:
     *  - Schema: это схема ЭЛЕМЕНТА массива
     *  - array<string,Schema>: элемент массива = объект с такими полями
     *  - string: массив строк
     */
    private static function buildArrayError(Schema|array|string|null $err): Schema
    {
        // Schema => это схема элемента
        if ($err instanceof Schema) {
            return Schema::array()->items($err);
        }

        // array<string,Schema> => элемент массива это объект с этими полями
        if (is_array($err)) {
            $item = Schema::object();
            foreach ($err as $fieldName => $fieldSchema) {
                if (! $fieldSchema instanceof Schema) {
                    throw new RuntimeException(
                        'Для array-ошибки значения массива должны быть Schema.'
                    );
                }

                $item = $item->propertiesNamed([(string)$fieldName => $fieldSchema]);
            }

            return Schema::array()->items($item);
        }

        // string|null => массив строк
        return Schema::array()->items(
            Schema::string()->default((string)$err)
        );
    }

    /**
     * Построение схемы для случая error_type = 'string'
     *
     * Варианты:
     *  - string|null => просто строка с default
     *  - Schema|array => сводим к строке "[error payload]"
     */
    private static function buildStringError(Schema|array|string|null $err): Schema
    {
        if (is_string($err) || $err === null) {
            return Schema::string()->default(is_string($err) ? $err : 'error');
        }

        // Если кто-то передал Schema или массив полей, а тип "string"
        return Schema::string()->default('[error payload]');
    }

    // -----------------------------------------------------------------
    // Пресеты
    // -----------------------------------------------------------------

    public static function badRequest(
        Schema|array|string|null $error = null,
        string $message = 'Bad Request'
    ): Response {
        return self::build(
            error: $error,
            response_description: 'Ответ с ошибкой',
            error_type: 'object',
            code: 400,
            message: $message,
        );
    }

    public static function notFound(
        Schema|array|string|null $error = null,
        string $message = 'Not Found'
    ): Response {
        return self::build(
            error: $error,
            response_description: 'Ответ с ошибкой',
            error_type: 'object',
            code: 404,
            message: $message,
        );
    }

    public static function validationError(
        Schema|array|string|null $error = null,
        string $message = 'Unprocessable Entity'
    ): Response {
        /**
         * Валидационная ошибка по умолчанию:
         * error: {
         *   field: string[] = ["validation error"]
         * }
         */
        $errSchema = $error;

        if ($error === null) {
            $fieldErrorsItem = Schema::string()->default('validation error');

            $errSchema = [
                'field' => Schema::array()->items($fieldErrorsItem),
            ];
        }

        return self::build(
            error: $errSchema,
            response_description: 'Ответ с ошибкой',
            error_type: 'object',
            code: 422,
            message: $message,
        );
    }

    public static function serverError(
        Schema|array|string|null $error = null,
        string $message = 'Server Error'
    ): Response {
        return self::build(
            error: $error,
            response_description: 'Ответ с ошибкой',
            error_type: 'string',
            code: 500,
            message: $message,
        );
    }
}
