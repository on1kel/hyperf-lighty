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
        $schema = Schema::object();

        foreach ($rules as $name => $rule) {
            $ruleString = is_array($rule) ? implode('|', $rule) : (string)$rule;

            // Определяем тип
            $type = $this->inferTypeFromRule($ruleString);

            // Создаём Schema для свойства
            $prop = match ($type) {
                'integer' => Schema::integer($name),
                'number' => Schema::number($name),
                'boolean' => Schema::boolean($name),
                'array' => Schema::array($name),
                'file' => Schema::string($name)->format('binary'),
                default => Schema::string($name),
            };

            if (str_contains($ruleString, 'nullable')) {
                $prop = $prop->nullable(true);
            }



            $schema = $schema->propertiesNamed([$name => $prop]);

            if (str_contains($ruleString, 'required')) {
                $schema = $schema->required($name);
            }
        }

        return $schema;
    }

    /**
     * Упрощённый детектор типа по строке правила.
     */
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
}
