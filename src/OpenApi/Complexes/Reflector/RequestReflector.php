<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\OpenApi\Complexes\Reflector;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ContainerInterface;
use Hyperf\HttpMessage\Server\Request as HyperfServerRequest;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Validation\Request\FormRequest;
use On1kel\OAS\Builder\Bodies\RequestBody;
use On1kel\OAS\Builder\Media\MediaType;
use On1kel\OAS\Builder\Schema\Schema;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionException;
use Throwable;

/**
 * RequestReflector
 *
 * Строит OpenAPI RequestBody билдер на основании Hyperf FormRequest.
 * Работает с on1kel/oas-builder напрямую (без DTO).
 */
final class RequestReflector
{
    public function __construct()
    {
    }

    /**
     * Построить RequestBody билдер.
     *
     * @param class-string<FormRequest> $requestClass
     * @param string|null $forceContentType
     * @param bool $required
     * @return RequestBody
     * @throws ReflectionException
     */
    public function reflect(string $requestClass, ?string $forceContentType = null, bool $required = true): RequestBody
    {
        // Создаём FormRequest без выполнения логики
        $req = $this->makeFormRequest($requestClass);

        // Забираем правила валидации
        $rules = (array)$req->rules();

        // Преобразуем правила в OAS Schema
        $schema = $this->buildSchemaFromRules($rules);

        // Определяем content-type
        $contentType = $forceContentType ?? $this->detectMediaType($schema);

        // Собираем RequestBody билдер
        return RequestBody::create()
            ->required($required)
            ->content(
                MediaType::of($contentType)
                    ->schema($schema)
            );
    }

    // ─────────────────────────── INTERNALS ───────────────────────────

    /**
     * Преобразует Laravel/Hyperf правила в OAS Schema.
     * (упрощённая версия — только базовые типы и файл)
     *
     * @param array<string, mixed> $rules
     */
    private function buildSchemaFromRules(array $rules): Schema
    {
        // Строим дерево правил (root-узел без имени)
        $tree = $this->buildRulesTree($rules);

        // Корневой объект тела запроса
        $schema = Schema::object();

        // Дети root-а — это верхнеуровневые поля (data, settings и т.п.)
        foreach ($tree['children'] as $name => $node) {
            $prop = $this->nodeToSchema($node, $name);

            if ($node['nullable']) {
                $prop = $prop->nullable(true);
            }

            $schema = $schema->propertiesNamed([$name => $prop]);

            if ($node['required']) {
                $schema = $schema->required($name);
            }
        }

        return $schema;
    }

    private function inferTypeFromRule(string $rule): string
    {
        $lower = strtolower($rule);

        return match (true) {
            str_contains($lower, 'int'),
            str_contains($lower, 'integer') => 'integer',
            str_contains($lower, 'numeric'),
            str_contains($lower, 'decimal'),
            str_contains($lower, 'float'),
            str_contains($lower, 'double') => 'number',
            str_contains($lower, 'bool') => 'boolean',
            str_contains($lower, 'array') => 'array',
            str_contains($lower, 'file'),
            str_contains($lower, 'image'),
            str_contains($lower, 'mimes'),
            str_contains($lower, 'mimetypes') => 'file',
            default => 'string',
        };
    }


    /**
     * Определяет Media Type по схеме.
     *
     * - Если корень — бинарь → application/octet-stream
     * - Если внутри есть бинарь → multipart/form-data
     * - Иначе → application/json
     */
    private function detectMediaType(Schema $schema): string
    {
        $model = $schema->toModel();

        // 1) Явный format=binary на корне
        if ($this->isBinarySchema($model)) {
            return 'application/octet-stream';
        }

        // 2) где-то внутри есть файл
        if ($this->hasBinaryInTree($model)) {
            return 'multipart/form-data';
        }

        return 'application/json';
    }

    /**
     * Проверка: текущий Core\Schema — бинарный файл?
     */
    private function isBinarySchema(object $model): bool
    {
        $type = $this->readProp($model, 'type');
        $extra = $this->readProp($model, 'extraKeywords');
        $format = $extra['format'] ?? null;

        return (
            ($type === 'string' || $type === ['string']) &&
            in_array($format, ['binary', 'base64'], true)
        );
    }

    /**
     * Рекурсивно ищет бинарные поля внутри дерева Core\Schema.
     */
    private function hasBinaryInTree(object $model): bool
    {
        $extra = $this->readProp($model, 'extraKeywords');
        if (is_array($extra) && isset($extra['format']) && in_array($extra['format'], ['binary', 'base64'], true)) {
            return true;
        }

        foreach ($model as $value) {
            if (is_object($value) && $this->hasBinaryInTree($value)) {
                return true;
            }
            if (is_array($value)) {
                foreach ($value as $v) {
                    if (is_object($v) && $this->hasBinaryInTree($v)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Безопасное чтение приватных свойств Core\Schema.
     */
    private function readProp(object $obj, string $name): mixed
    {
        $ref = new \ReflectionClass($obj);
        if ($ref->hasProperty($name)) {
            $p = $ref->getProperty($name);
            $p->setAccessible(true);

            return $p->getValue($obj);
        }

        return null;
    }

    /**
     * Создаёт экземпляр FormRequest без вызова конструкторов.
     *
     * @param class-string<FormRequest> $class
     * @return FormRequest
     * @throws ReflectionException
     */
    private function makeFormRequest(string $class): FormRequest
    {
        /** @var ContainerInterface $container */
        $container = ApplicationContext::getContainer();

        $serverRequest = $this->resolveServerRequest($container);

        try {
            /** @var FormRequest $req */
            $req = $container->make($class, [
                'container' => $container,
                'request' => $serverRequest,
            ]);

            return $req;
        } catch (Throwable) {
            $ref = new ReflectionClass($class);

            return $ref->newInstanceWithoutConstructor();
        }
    }

    private function resolveServerRequest(ContainerInterface $container): ServerRequestInterface
    {
        if ($container->has(ServerRequestInterface::class)) {
            return $container->get(ServerRequestInterface::class);
        }

        if ($container->has(RequestInterface::class)) {
            return $container->get(RequestInterface::class)->getRequest();
        }

        return new HyperfServerRequest('GET', '/fly-docs');
    }
// ─────────────────────────── RULES TREE ───────────────────────────

    /**
     * Узел дерева правил.
     *
     * @return array{
     *     type: ?string,
     *     rules: ?string,
     *     required: bool,
     *     nullable: bool,
     *     children: array<string, array>,
     *     wildcard: ?array
     * }
     */
    private function makeEmptyNode(): array
    {
        return [
            'type' => null,        // 'string'|'integer'|'number'|'boolean'|'file'|'array'|null
            'rules' => null,       // сырая строка правил
            'required' => false,
            'nullable' => false,
            'children' => [],
            'wildcard' => null,
        ];
    }

    /**
     * Строим дерево правил по dot-нотации и "*".
     *
     * Примеры:
     * - data               → root.children['data']
     * - data.name          → root.children['data'].children['name']
     * - data.*             → root.children['data'].wildcard
     * - data.*.name        → root.children['data'].wildcard.children['name']
     *
     * @param array<string, mixed> $rules
     * @return array
     */
    private function buildRulesTree(array $rules): array
    {
        $root = $this->makeEmptyNode();

        foreach ($rules as $name => $rule) {
            $ruleString = is_array($rule) ? implode('|', $rule) : (string)$rule;
            $type       = $this->inferTypeFromRule($ruleString);

            $segments = explode('.', (string)$name);
            $current  =& $root;

            foreach ($segments as $index => $segment) {
                $isLast = ($index === array_key_last($segments));

                if ($segment === '*') {
                    if ($current['wildcard'] === null) {
                        $current['wildcard'] = $this->makeEmptyNode();
                    }
                    $current =& $current['wildcard'];
                } else {
                    if (! isset($current['children'][$segment])) {
                        $current['children'][$segment] = $this->makeEmptyNode();
                    }
                    $current =& $current['children'][$segment];
                }

                if ($isLast) {
                    $current['rules']    = $ruleString;
                    $current['type']     = $type;
                    $current['required'] = str_contains($ruleString, 'required');
                    $current['nullable'] = str_contains($ruleString, 'nullable');
                }
            }
        }

        return $root;
    }

    /**
     * Преобразование узла дерева в Schema.
     *
     * @param array $node
     * @param string|null $name имя свойства (null для анонимных узлов — items и т.п.)
     */
    private function nodeToSchema(array $node, ?string $name = null): Schema
    {
        // 1. Если есть wildcard → это массив (data.*)
        if ($node['wildcard'] !== null) {
            // Массив элементов; схема элемента = wildcard-узел
            $itemsSchema = $this->nodeToSchema($node['wildcard'], null);

            // ПРЕДПОЛОЖЕНИЕ:
            // У Schema::array($name) есть метод items(Schema $schema).
            // Если у тебя другой метод для items — просто замени его здесь.
            $schema = Schema::array($name ?? 'item');
            $schema = $schema->items($itemsSchema);

            if ($node['nullable']) {
                $schema = $schema->nullable(true);
            }

            return $schema;
        }

        // 2. Если есть именованные дети → объект
        if (! empty($node['children'])) {
            // Объект; имя самого свойства задаётся выше через propertiesNamed()
            $schema = Schema::object();

            foreach ($node['children'] as $childName => $childNode) {
                $childSchema = $this->nodeToSchema($childNode, $childName);

                if ($childNode['nullable']) {
                    $childSchema = $childSchema->nullable(true);
                }

                $schema = $schema->propertiesNamed([$childName => $childSchema]);

                if ($childNode['required']) {
                    $schema = $schema->required($childName);
                }
            }

            if ($node['nullable']) {
                $schema = $schema->nullable(true);
            }

            return $schema;
        }

        // 3. Лист, тип = array → «произвольный объект» (any_custom_data)
        if ($node['type'] === 'array') {
            $object = Schema::object();

            // Здесь мы показываем, что внутри — любые кастомные ключи (string).
            $dummy = Schema::string('any_custom_data');
            $object = $object->propertiesNamed([
                'any_custom_data' => $dummy,
            ]);

            if ($node['nullable']) {
                $object = $object->nullable(true);
            }

            return $object;
        }

        // 4. Обычный скаляр
        $schema = $this->makeScalarSchema($node['type'], $name);

        if ($node['nullable']) {
            $schema = $schema->nullable(true);
        }

        return $schema;
    }

    /**
     * Создаёт скалярную Schema по типу, который вернул inferTypeFromRule().
     */
    private function makeScalarSchema(?string $type, ?string $name): Schema
    {
        $fieldName = $name ?? '';

        return match ($type) {
            'integer' => Schema::integer($fieldName),
            'number'  => Schema::number($fieldName),
            'boolean' => Schema::boolean($fieldName),
            'file'    => Schema::string($fieldName)->format('binary'),
            default   => Schema::string($fieldName),
        };
    }
}
