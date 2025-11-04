<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Exceptions;

use Hyperf\Contract\ConfigInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Base\Response as BaseResponse;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Validation\ValidationException;
use On1kel\HyperfLighty\Http\Controllers\Api\DTO\ApiResponseDTO;
use On1kel\HyperfLighty\Http\Respond\Respondable;
use On1kel\HyperfLighty\Http\Respond\RespondableInterface;
use ReflectionException;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;
use Swow\Psr7\Message\ResponsePlusInterface;
use Throwable;

class JsonExceptionHandler extends ExceptionHandler implements RespondableInterface
{
    use Respondable;

    /** JSON флаги: без экранирования слэшей/юникода + исключение при ошибке JSON. */
    public int $json_flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Исключения, для которых trace не прикладываем даже в debug.
     * Только Hyperf/соседние экосистемы — без Laravel.
     *
     * @var array<class-string>
     */
    public array $ignore_trace = [
        ValidationException::class,
//        // Если используете hyperf-ext/auth:
//        \HyperfExt\Auth\Exceptions\AuthenticationException::class,
    ];

    public function __construct(private readonly ConfigInterface $config)
    {
    }

    /**
     * Точка входа Hyperf ExceptionHandler.
     *
     * @param Throwable $throwable
     * @param ResponsePlusInterface $response
     * @return ResponsePlusInterface
     * @throws ReflectionException
     * @throws UnknownProperties
     */
    public function handle(Throwable $throwable, ResponsePlusInterface $response): ResponsePlusInterface
    {
        $errorData = $this->isValidationException($throwable)
            ? $this->extractValidationErrors($throwable)
            : $throwable->getMessage();

        $errorTrace = null;

        if ($this->isDebugEnabled() && $this->needTrace($throwable)) {
            $json = json_encode($throwable->getTrace(), $this->json_flags);

            if (is_string($json)) {
                /** @var array<int|string, mixed> $errorTrace */
                $errorTrace = json_decode($json, true, 512, $this->json_flags);
            } else {
                /** @var array<string|int,mixed> $errorTrace */
                $errorTrace = [];
            }
        }

        $status = $this->resolveHttpStatus($throwable);
        $code = $this->normalizeStatusCode((int) $throwable->getCode());
        /** @var array<string,mixed> $body */
        $body = [
            'status' => 'error',
            'code' => $code,
            'message' => BaseResponse::getReasonPhraseByCode($code),
            'error' => $errorData,
        ];
        if ($errorTrace !== null) {
            $body['meta'] = isset($body['meta']) && is_array($body['meta']) ? $body['meta'] : [];
            $body['meta']['trace'] = $errorTrace;
        }

        // Если есть ваш DTO — используем его
        if (class_exists(ApiResponseDTO::class)) {
            $body = (new ApiResponseDTO($body))->toArray();
        }


        $payload = json_encode($body, $this->json_flags);
        if (! is_string($payload)) {
            throw new \RuntimeException('Не удалось сериализовать JSON: ' . json_last_error_msg());
        }

        $this->stopPropagation();

        // Swow ожидает StreamInterface — отдаём SwooleStream (он универсален)
        $response->setStatus($status);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setBody(new SwooleStream($payload));

        return $response;
    }

    /** Обрабатываем любые исключения. */
    public function isValid(Throwable $throwable): bool
    {
        return true;
    }

    private function normalizeStatusCode(int $code): int
    {
        return ($code >= 100 && $code <= 599) ? $code : 400;
    }

    private function isDebugEnabled(): bool
    {
        return (bool) $this->config->get('debug', true);
    }

    private function needTrace(Throwable $e): bool
    {
        foreach ($this->ignore_trace as $fqcn) {
            if (class_exists($fqcn) && $e instanceof $fqcn) {
                return false;
            }
        }

        return true;
    }

    private function isValidationException(Throwable $e): bool
    {
        return class_exists(ValidationException::class)
            && $e instanceof ValidationException;
    }

    /**
     * @param Throwable $e
     * @return array<string|int,mixed>
     */
    private function extractValidationErrors(Throwable $e): array
    {
        /** @var ValidationException $e */
        return $e->errors();
    }

    private function resolveHttpStatus(Throwable $e): int
    {
        if ($this->isValidationException($e)) {
            return 422;
        }

        //        if (class_exists(\HyperfExt\Auth\Exceptions\AuthenticationException::class)
        //            && $e instanceof \HyperfExt\Auth\Exceptions\AuthenticationException) {
        //            return 401;
        //        }
        return 400;
    }
}
