<?php

namespace On1kel\HyperfLighty\Domain\Listeners;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Model\Events\Created;
use Hyperf\Database\Model\Events\Creating;
use Hyperf\Database\Model\Events\Deleted;
use Hyperf\Database\Model\Events\Deleting;
use Hyperf\Database\Model\Events\Updated;
use Hyperf\Database\Model\Events\Updating;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use On1kel\HyperfLighty\Domain\Contracts\Action as ActionContract;
use On1kel\HyperfLighty\Domain\Contracts\AfterCommit;
use On1kel\HyperfLighty\Domain\Contracts\HasQueue;
use On1kel\HyperfLighty\Domain\Contracts\HasTries;
use On1kel\HyperfLighty\Domain\Contracts\ShouldQueue;
use On1kel\HyperfLighty\Domain\Support\AfterCommitManager;
use On1kel\HyperfLighty\Domain\Support\QueuedActionJob;
use On1kel\HyperfLighty\Models\Model;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

#[Listener]
final class UniversalModelEventsRouter implements ListenerInterface
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ContainerInterface $container,
        private readonly DriverFactory $driverFactory,
        private readonly AfterCommitManager $afterCommit,
    ) {
    }

    public function listen(): array
    {
        return [
            Creating::class, Created::class,
            Updating::class, Updated::class,
            Deleting::class, Deleted::class,
        ];
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function process(object $event): void
    {
        if (! method_exists($event, 'getModel')) {
            return;
        }

        /** @var Model $model */
        $model = $event->getModel();
        $modelClass = $model::class;

        /** @var array<string, string> $map */
        $map = $this->config->get('model_events.map', []);
        $key = $map[$modelClass] ?? null;
        if ($key === null) {
            return;
        }

        /** @var array<string, string[]> $payload */
        $payload = $this->config->get("model_events.payload.{$key}", []);
        $actions = $payload[$event::class] ?? [];
        if (! $actions) {
            return;
        }

        // Порядок в массиве = порядок выполнения
        foreach ($actions as $actionClass) {
            $this->dispatchAction($actionClass, $model);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function dispatchAction(string $actionClass, object $model): void
    {
        $action = $this->container->get($actionClass);

        if (! $action instanceof ActionContract) {
            return;
        }

        $shouldQueue = $action instanceof ShouldQueue;
        $afterCommit = $action instanceof AfterCommit;

        /** @var string $queue */
        $queue = ($action instanceof HasQueue)
            ? $action->queue()
            : (property_exists($action, 'queue') ? $action->queue : 'domain');

        /** @var int $tries */
        $tries = ($action instanceof HasTries)
            ? $action->tries()
            : (property_exists($action, 'tries') ? $action->tries : 1);

        // Комбинации: AfterCommit + Queue, только AfterCommit, только Queue, или Sync Now
        if ($afterCommit && $shouldQueue) {
            $this->afterCommit->run(function () use ($actionClass, $model, $queue, $tries) {
                $this->enqueue($actionClass, $model, $queue, $tries);
            });

            return;
        }

        if ($afterCommit) {
            $this->afterCommit->run(function () use ($action, $model) {
                $action->handle($model);
            });

            return;
        }

        if ($shouldQueue) {
            $this->enqueue($actionClass, $model, $queue, $tries);

            return;
        }

        $action->handle($model);
    }

    private function enqueue(string $actionClass, object $model, string $queue, int $tries): void
    {
        $driver = $this->driverFactory->get($queue);

        $job = new QueuedActionJob($actionClass, $model);
        $job->setMaxAttempts(max(1, $tries));

        $driver->push($job);
    }
}
