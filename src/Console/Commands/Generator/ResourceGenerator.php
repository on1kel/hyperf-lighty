<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Console\Commands\Generator;

use Hyperf\Command\Annotation\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[Command(name: 'lighty:generate-resource', description: 'Generate resource class')]
final class ResourceGenerator extends BaseGenerator
{
    protected string $description = 'Метод для генерации ресурсов с использованием предлагаемой пакетом архитектуры.';

    /**
     * @var array<string,string>
     */
    private const AVAILABLE_STUBS = [
        'single' => 'resource.single.stub',
        'collection' => 'resource.collection.stub',
    ];

    /** @var array<string,string> */
    private array $modelMeta = [];

    private string $currentStub = '';

    public function __construct()
    {
        parent::__construct('lighty:generate-resource');
    }

    public function configure(): void
    {
        $this->addArgument(
            'resource_name',
            InputArgument::REQUIRED,
            'Название ресурса. Используйте слэш (/) для вложенности.'
        );

        $this->addArgument(
            'model_name',
            InputArgument::REQUIRED,
            'Название модели. Используйте слэш (/) для вложенности.'
        );

        $this->addOption(
            'type',
            null,
            InputOption::VALUE_REQUIRED,
            'Тип ресурса - s|single или c|collection',
            'single'
        );
    }

    public function initGeneratorParams(): void
    {
        // Локация ресурсов в проекте:
        $this->default_generator_namespace = 'App\\Http\\Resources';
        $this->default_generator_dir = 'Http/Resources';
    }

    public function handle(): int
    {
        $this->initGeneratorParams();

        /** @var string $nameRaw */
        $nameRaw = (string) $this->input->getArgument('resource_name');
        if (! $this->isValidClassName($nameRaw)) {
            $this->output->error('Недопустимое имя ресурса. Разрешены буквы/цифры/подчёркивание и "/" для вложенности.');

            return self::FAILURE;
        }

        /** @var string $modelRaw */
        $modelRaw = (string) $this->input->getArgument('model_name');
        if (! $this->isValidClassName($modelRaw)) {
            $this->output->error('Недопустимое имя модели. Разрешены буквы/цифры/подчёркивание и "/" для вложенности.');

            return self::FAILURE;
        }

        if (! $this->resolveStubByType()) {
            return self::FAILURE;
        }

        // Ветка для неймспейса коллекции/ресурса: App\Http\Resources\{FolderName}\{ResourceName}{Resource|Collection}.php
        $meta = $this->getMetaFromClassName($nameRaw);
        $this->default_generator_namespace .= '\\' . $meta['name'];
        $this->default_generator_dir .= '/' . $meta['name'];

        // Имя класса в зависимости от типа
        $type = $this->normalizeType((string) $this->input->getOption('type'));
        $className = $nameRaw . ($type === 'collection' ? 'Collection' : 'Resource');

        $this->initGenerator($className);

        // Модель (по умолчанию Hyperf: App\Model)
        $this->modelMeta = $this->getMetaFromClassName(
            $modelRaw,
            'App\\Model',
            'Model'
        );

        if ($this->checkClassExist($this->class_path)) {
            $this->output->warning("Ресурс [{$this->class_name}] уже существует.");

            return self::FAILURE;
        }

        if (! $this->createClass()) {
            $this->output->warning("Не получилось создать ресурс ({$this->class_path}).");

            return self::FAILURE;
        }

        $this->output->info("Ресурс [{$this->class_name}] создан успешно.");

        return self::SUCCESS;
    }

    public function makeClassData(): string
    {
        $data = (string) @file_get_contents($this->currentStub);

        return $this->render($data, [
            '{{ resource_namespace }}' => $this->class_namespace,
            '{{ resource_name }}' => $this->class_name,
            '{{ model_namespace }}' => $this->modelMeta['namespace'] ?? 'App\\Model',
            '{{ model_name }}' => $this->modelMeta['name'] ?? 'Model',
        ]);
    }

    private function resolveStubByType(): bool
    {
        $type = $this->normalizeType((string) $this->input->getOption('type'));
        $key = $type === 'collection' ? 'collection' : 'single';

        $stub = $this->stubPath(self::AVAILABLE_STUBS[$key]);
        if (! file_exists($stub)) {
            $this->output->warning('Заготовка не найдена: ' . $stub);

            return false;
        }

        $this->currentStub = $stub;

        return true;
    }

    private function normalizeType(string $t): string
    {
        return match ($t) {
            's' => 'single',
            'c' => 'collection',
            default => $t,
        };
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

    private function stubPath(string $file): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'Stubs' . DIRECTORY_SEPARATOR . $file;
    }
}
