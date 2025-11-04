<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services\CRUD\Events;

use Spatie\DataTransferObject\DataTransferObject;
use Throwable;

/**
 * Базовый CRUD-ивент для Hyperf.
 * Никаких Laravel-трейтов — просто данные.
 */
abstract class BaseCRUDEvent
{
    /**
     * @param class-string $modelClass
     * @param mixed $data
     * @param Throwable|null $exception
     */
    public function __construct(
        public string $modelClass,
        public mixed $data,
        public ?Throwable $exception = null,
    ) {
        if ($this->data instanceof DataTransferObject) {
            $this->data = $this->data->toArray();
        }
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getException(): ?Throwable
    {
        return $this->exception;
    }

    public function hasException(): bool
    {
        return $this->exception !== null;
    }
}
