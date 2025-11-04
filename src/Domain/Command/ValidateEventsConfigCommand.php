<?php

namespace On1kel\HyperfLighty\Domain\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use ReflectionClass;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @property SymfonyStyle $output
 * @property InputInterface $input
 */
#[Command]
class ValidateEventsConfigCommand extends HyperfCommand
{
    public function __construct(
        private readonly ConfigInterface $config
    ) {
        parent::__construct('model-events:validate');
    }

    public function handle(): int
    {
        /** @var array<string, string> $map */
        $map = $this->config->get('model_events.map', []);
        $errors = [];

        foreach ($map as $modelClass => $key) {
            /** @var array<string, string[]> $payload */
            $payload = $this->config->get("model_events.payload.{$key}", []);
            foreach ($payload as $eventClass => $actions) {
                foreach ($actions as $actionClass) {
                    if (! class_exists($actionClass)) {
                        $errors[] = "Отсутствует класс: {$actionClass}";

                        continue;
                    }
                    $rc = new ReflectionClass($actionClass);
                    if (! $rc->hasMethod('handle')) {
                        $errors[] = "Отсутствует handle() в {$actionClass}";

                        continue;
                    }
                }
            }
        }

        if ($errors) {
            foreach ($errors as $e) {
                $this->error($e);
            }
            $this->output->error('Ошибка валидации');

            return self::FAILURE;
        }

        $this->output->success('События модели сконфигурированы верно');

        return self::SUCCESS;
    }
}
