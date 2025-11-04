<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Controllers;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

abstract class Controller
{
    /** Текущий HTTP-запрос */
    final protected function container(): ContainerInterface
    {
        return ApplicationContext::getContainer();
    }

    final protected function request(): RequestInterface
    {
        return $this->container()->get(RequestInterface::class);
    }

    final protected function response(): HttpResponse
    {
        return $this->container()->get(HttpResponse::class);
    }

    final protected function config(): ConfigInterface
    {
        return $this->container()->get(ConfigInterface::class);
    }

    final protected function validator(): ValidatorFactoryInterface
    {
        return $this->container()->get(ValidatorFactoryInterface::class);
    }

    /**
     * Тип текущего действия контроллера (index|show|store|update|destroy).
     */
    protected string $current_action = '';

    /**
     * Мета-опции для действий контроллера.
     * @var array<string, mixed>
     */
    protected array $options = [];

    //    public function __construct(
    //        ContainerInterface $container,
    //        RequestInterface $request,
    //        HttpResponse $response,
    //        ConfigInterface $config,
    //        ValidatorFactoryInterface $validator
    //    ) {
    //        $this->container = $container;
    //        $this->request   = $request;
    //        $this->response  = $response;
    //        $this->config    = $config;
    //        $this->validator = $validator;
    //    }

    /* =======================
       ВАЛИДАЦИЯ (вместо ValidatesRequests)
       ======================= */

    /**
     * Быстрая валидация данных.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>      $rules
     * @param array<string, string>     $messages
     * @param array<string, string>     $attributes
     * @return array<string|int, mixed>     Валидированные данные
     * @throws ValidationException
     */
    protected function validate(array $data, array $rules, array $messages = [], array $attributes = []): array
    {
        $validator = $this->validator()->make($data, $rules, $messages, $attributes);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Валидация текущего запроса (из $this->request->all()).
     *
     * @param array<string, mixed>  $rules
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     * @return array<string|int, mixed>
     */
    protected function validateRequest(array $rules, array $messages = [], array $attributes = []): array
    {
        /** @var array<string, mixed> $input */
        $input = $this->request()->all();

        return $this->validate($input, $rules, $messages, $attributes);
    }

    /* =======================
       УТИЛИТЫ ДЛЯ ОТВЕТОВ
       ======================= */

    protected function json(array $payload, int $status = 200, array $headers = []): ResponseInterface
    {
        $resp = $this->response()->json($payload)->withStatus($status);
        foreach ($headers as $name => $value) {
            $resp = $resp->withHeader((string)$name, (string)$value);
        }

        return $resp;
    }

    protected function noContent(int $status = 204): ResponseInterface
    {
        return $this->response()->raw('')->withStatus($status);
    }

    /**
     * Set option.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function setOption(string $key, mixed $value): mixed
    {
        return helper_array_set($this->options, $key, $value);
    }

    /**
     * Получить все опции.
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Перезаписать все опции.
     * @param array<string, mixed> $options
     */
    protected function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * Get option.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function getOption(string $key): mixed
    {
        $result = helper_array_get($this->options, $key);

        return $result ?? false;
    }

    /**
     * Установить тип текущего действия (index|show|store|update|destroy).
     */
    protected function setCurrentAction(string $current_action): void
    {
        $this->current_action = $current_action;
    }
}
