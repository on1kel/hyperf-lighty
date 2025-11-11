<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Console\Commands\Generator;

use Hyperf\Command\Annotation\Command;
use Symfony\Component\Console\Input\InputArgument;

#[Command(name: 'lighty:generate-request', description: 'Generate request class')]
final class RequestGenerator extends BaseGenerator
{
    protected string $description = 'Метод для генерации запросов с использованием предлагаемой пакетом архитектуры.';

    /**
     * Доступные шаблоны.
     * @var array<string,string>
     */
    private const AVAILABLE_STUBS = [
        'base' => 'request.stub',
    ];

    private string $currentStub = '';

    public function __construct()
    {
        parent::__construct('lighty:generate-request');
    }

    public function configure(): void
    {
        $this->addArgument(
            'request_name',
            InputArgument::REQUIRED,
            'Название запроса. Используйте слэш (/) для вложенности.'
        );
    }

    public function initGeneratorParams(): void
    {
        $this->default_generator_namespace = 'App\\Requests';
        $this->default_generator_dir = 'Http/Requests';
    }

    public function handle(): int
    {
        $this->initGeneratorParams();

        /** @var string $requestNameRaw */
        $requestNameRaw = (string) $this->input->getArgument('request_name');
        if (! $this->isValidClassName($requestNameRaw)) {
            $this->output->error('Недопустимое имя запроса. Разрешены буквы/цифры/подчёркивание и "/" для вложенности.');

            return self::FAILURE;
        }

        if (! $this->resolveStub()) {
            return self::FAILURE;
        }

        $this->initGenerator($requestNameRaw);

        if ($this->checkClassExist($this->class_path)) {
            $this->output->warning("Запрос [{$this->class_name}] уже существует.");

            return self::FAILURE;
        }

        if (! $this->createClass()) {
            $this->output->warning("Не получилось создать запрос ({$this->class_path}).");

            return self::FAILURE;
        }

        $this->output->info("Запрос [{$this->class_name}] создан успешно.");

        return self::SUCCESS;
    }

    public function makeClassData(): string
    {
        $data = (string) @file_get_contents($this->currentStub);

        return $this->render($data, [
            '{{ request_namespace }}' => $this->class_namespace,
            '{{ request_name }}' => $this->class_name,
        ]);
    }

    private function resolveStub(): bool
    {
        $stub = $this->stubPath(self::AVAILABLE_STUBS['base']);
        if (! file_exists($stub)) {
            $this->output->warning('Заготовка с запросом не найдена: ' . $stub);

            return false;
        }
        $this->currentStub = $stub;

        return true;
    }

    private function stubPath(string $file): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'Stubs' . DIRECTORY_SEPARATOR . $file;
    }

    private function isValidClassName(string $name): bool
    {
        foreach (explode('/', $name) as $seg) {
            if ($seg === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $seg)) {
                return false;
            }
        }

        return true;
    }
}
