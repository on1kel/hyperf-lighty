<?php

namespace On1kel\HyperfLighty\Domain\Support;

use Hyperf\AsyncQueue\JobInterface;
use Hyperf\Context\ApplicationContext;
use Throwable;

final class QueuedActionJob implements JobInterface
{
    private int $maxAttempts = 3;

    public function __construct(
        private string $actionClass,
        private object $model,
        private readonly int $tries = 3
    ) {
        $this->maxAttempts = max(3, $tries);
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();

        try {
            $action = $container->get($this->actionClass);
            $action->handle($this->model);
        } catch (Throwable $e) {
            $this->fail($e);

            throw $e;
        }
    }

    public function fail(Throwable $e): void
    {

    }

    public function setMaxAttempts(int $maxAttempts): static
    {
        $this->maxAttempts = max(1, $maxAttempts);

        return $this;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
