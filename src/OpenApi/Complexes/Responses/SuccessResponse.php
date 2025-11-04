<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\Responses;

use On1kel\OAS\Builder\Media\MediaType;
use On1kel\OAS\Builder\Responses\Response;
use On1kel\OAS\Builder\Schema\Schema;
use On1kel\OAS\Core\Model\Reference;
use RuntimeException;

final class SuccessResponse
{
    /**
     * Универсальный успешный ответ:
     *
     * {
     *   status: "success",
     *   code:   <int>,
     *   message:"OK",
     *   data:   <...зависит от $data_type...>,
     *   ...$additional_properties
     * }
     *
     * $data_type:
     *  - 'object' => data: { ... }            // структура объекта, либо $ref на объект
     *  - 'array'  => data: [ { ... }, ... ]   // коллекция элементов
     *  - 'string' => data: "..."              // примитивная строка
     *
     * $data:
     *  - Schema                (inline схема)
     *  - Reference             (результат ComponentsRegistry->getOrRegisterSchema(), $ref)
     *  - string                (либо "#/components/schemas/Model" как $ref, либо plain string для data_type='string')
     *  - array<string,Schema>  (map полей для объекта; используется только с data_type='object')
     */
    public static function build(
        Schema|Reference|string|array $data,
        array $additional_properties = [], // ['meta' => Schema, ...]
        string $response_description = 'Успешный ответ',
        string $data_type = 'object',       // 'object' | 'array' | 'string'
        int $code = 200,
        string $message = 'OK',
        string $contentType = 'application/json',
    ): Response {
        // 1. Проверка корректности режима
        $available = ['object', 'array', 'string'];
        if (! in_array($data_type, $available, true)) {
            throw new RuntimeException(sprintf(
                'Неверный data_type (%s). Допустимо: %s',
                $data_type,
                implode(',', $available)
            ));
        }

        // 2. Базовая "обёртка" без data / meta
        $root = Schema::object()
            ->properties(
                Schema::string('status')
                    ->description('Статус запроса')
                    ->default('success'),
                Schema::integer('code')
                    ->description('HTTP код ответа')
                    ->default($code),
                Schema::string('message')
                    ->description('Сообщение ответа')
                    ->default($message),
            );

        // 3. Схема поля data
        $dataSchema = match ($data_type) {
            'object' => self::buildDataAsObject($data),
            'array' => self::buildDataAsArray($data),
            'string' => self::buildDataAsString($data),
        };


        $root = $root->propertiesNamed([
            'data' => $dataSchema,
        ]);

        // 4. Дополнительные корневые поля (meta и т.п.)
        //    ожидаем, что это ['meta' => Schema, ...]
        foreach ($additional_properties as $fieldName => $fieldSchema) {
            if (! is_string($fieldName) || $fieldName === '') {
                throw new RuntimeException('additional_properties: ключ должен быть непустой строкой');
            }
            if (! $fieldSchema instanceof Schema) {
                throw new RuntimeException('additional_properties: значение должно быть Schema');
            }

            $root = $root->propertiesNamed([
                $fieldName => $fieldSchema,
            ]);
        }

        // 5. Собираем Response
        return Response::code($code)
            ->description($response_description)
            ->contentMap([
                $contentType => MediaType::of($contentType)->schema($root),
            ]);
    }

    /**
     * data_type = 'object'
     *
     * $data может быть:
     *  - Schema (описание объекта)
     *  - Reference (Ref на компонент)
     *  - string "#/components/schemas/Model"
     *  - array<string,Schema> карта полей { fieldName => Schema }
     *
     * Возвращаем: Schema, которую можно положить в propertiesNamed(['data' => <...>]).
     */
    private static function buildDataAsObject(Schema|Reference|string|array $data): Schema|string
    {
        // 1. Если массив схем (ассоциативная карта полей объекта)
        //    Собираем Schema::object()->propertiesNamed([...])
        if (is_array($data)) {
            // проверяем, что это именно map вида ['field' => Schema, ...]
            $map = [];
            foreach ($data as $propName => $propSchema) {
                if (! is_string($propName) || $propName === '') {
                    throw new RuntimeException('buildDataAsObject: массив должен быть ассоциативным с string-ключами');
                }
                if (! $propSchema instanceof Schema) {
                    throw new RuntimeException('buildDataAsObject: значения массива должны быть Schema');
                }
                $map[$propName] = $propSchema;
            }

            return Schema::object()
                ->propertiesNamed($map);
        }

        // 2. Если Schema — уже готовая объектная схема, вернуть её напрямую
        if ($data instanceof Schema) {
            return $data;
        }

        // 3. Если Reference — нужно вытащить $ref-путь, вернуть строку "#/components/schemas/Model"
        if ($data instanceof Reference) {
            $refPath = self::extractRefPath($data);

            return $refPath;
        }

        // 4. Если string — это либо "$ref", либо plain string.
        //    В контексте object мы разрешаем ТОЛЬКО "$ref"-строку.
        if (is_string($data)) {
            if (! self::looksLikeRefPath($data)) {
                throw new RuntimeException(
                    'Ожидалась схема объекта или $ref ("#/components/..."), но передана обычная строка.'
                );
            }

            return $data; // "#/components/schemas/Model"
        }

        throw new RuntimeException('buildDataAsObject: неподдерживаемый тип');
    }

    /**
     * data_type = 'array'
     *
     * $data описывает ЭЛЕМЕНТ массива:
     *  - Schema       (inline элемент)
     *  - Reference    ($ref на компонент элемента)
     *  - string       "#/components/schemas/Model"
     *
     * Возвращаем Schema массива: Schema::array()->items(<Schema|string>).
     *
     * ВНИМАНИЕ: если кто-то передаст массив схем (array[..]),
     * это НЕ поддаётся чистой генерации items() (массива с разными типами),
     * поэтому считаем это ошибкой на этом уровне.
     */
    private static function buildDataAsArray(Schema|Reference|string|array $data): Schema
    {
        if (is_array($data)) {
            // это не список "разных структур" в одном массиве, OpenAPI так не умеет,
            // пусть вызывающий код сам сформирует allOf/oneOf извне, если нужно.
            throw new RuntimeException(
                'data_type=array не принимает array<Schema>. Передай схему элемента, а не массив схем.'
            );
        }


        $itemSchemaOrRef = self::normalizeToSchemaOrRefForItems($data);


        return Schema::array()
            ->items($itemSchemaOrRef);
    }

    /**
     * data_type = 'string'
     *
     * Разрешаем:
     *  - простую строку => Schema::string()->example(...)
     *  - Schema (если хочешь кастомный string Schema вручную)
     *
     * НЕ разрешаем Reference/реф-путь, потому что это не "строка", а объект.
     */
    private static function buildDataAsString(Schema|Reference|string|array $data): Schema
    {
        if (is_array($data)) {
            throw new RuntimeException('data_type=string не принимает массив');
        }

        if ($data instanceof Schema) {
            return $data;
        }

        if ($data instanceof Reference) {
            throw new RuntimeException('data_type=string не принимает Reference ($ref на объект)');
        }

        // string
        if (is_string($data)) {
            if (self::looksLikeRefPath($data)) {
                throw new RuntimeException('data_type=string не принимает $ref-путь, это не строковой примитив');
            }

            return Schema::string('data')
                ->example($data);
        }

        throw new RuntimeException('buildDataAsString: неподдерживаемый тип');
    }

    /**
     * Нормализуем "одну сущность" (Schema|Reference|string) в то,
     * что можно передать в ->items() (Schema|string).
     */
    private static function normalizeToSchemaOrRefForItems(Schema|Reference|string $data): Schema|string
    {
        if ($data instanceof Schema) {
            return $data;
        }

        if ($data instanceof Reference) {
            return self::extractRefPath($data); // "#/components/schemas/Model"
        }

        if (is_string($data)) {
            // либо "#/components/schemas/Model", либо (ошибочно) обычная строка
            if (! self::looksLikeRefPath($data)) {
                throw new RuntimeException(
                    'Для массива ожидается схема элемента или $ref ("#/components/..."), не plain string.'
                );
            }

            return $data;
        }

        throw new RuntimeException('normalizeToSchemaOrRefForItems: неподдерживаемый тип');
    }

    /**
     * Вытащить "#/components/schemas/Model" из Core Reference.
     * В on1kel/oas-builder Reference обычно содержит публичное свойство $ref
     * или геттер getRef()/ref(). Подстраиваемся.
     */
    private static function extractRefPath(Reference $ref): string
    {
        // Пытаемся найти строку с путём
        // Предположим при генерации мы делали Schema::ref('#/components/schemas/Model'),
        // значит Reference где-то хранит этот путь.
        if (property_exists($ref, 'ref') && is_string($ref->ref)) {
            return $ref->ref;
        }

        if (method_exists($ref, 'getRef')) {
            $val = $ref->getRef();
            if (is_string($val) && $val !== '') {
                return $val;
            }
        }

        throw new RuntimeException('Не удалось извлечь $ref путь из Reference');
    }

    /**
     * Хелпер: выглядит ли строка как $ref-путь в components.schemas.
     */
    private static function looksLikeRefPath(string $v): bool
    {
        // простая эвристика
        return str_starts_with($v, '#/components/schemas/');
    }
}
