<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Console\Commands\Generator;

use Hyperf\Command\Annotation\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[Command(name: 'lighty:generate-controller', description: 'Generate controller class')]
final class ControllerGenerator extends BaseGenerator
{
    protected string $description = 'Метод для генерации контроллеров с использованием предлагаемой пакетом архитектуры.';

    /**
     * Доступные шаблоны.
     * @var array<string,string>
     */
    private const AVAILABLE_STUBS = [
        'api' => 'controller.api.stub',
        'api-crud' => 'controller.api.crud.stub',
    ];

    /** @var array<string,string> */
    private array $modelMeta = [];

    /** Текущий выбранный stub-файл (полный путь). */
    private string $currentStub = '';

    /** Кэш нормализованного типа контроллера. */
    private ?string $normalizedType = null;

    /** Сырой префикс API из аргумента (например, v1.0) — идёт в URL. */
    private string $api_prefix_raw = '';

    public function __construct()
    {
        parent::__construct('lighty:generate-controller');
    }

    public function configure(): void
    {
        $this->addArgument(
            'controller_name',
            InputArgument::REQUIRED,
            'Название контроллера. Используйте слэш (/) для вложенности.'
        );

        $this->addArgument(
            'model_name',
            InputArgument::REQUIRED,
            'Название модели. Используйте слэш (/) для вложенности.'
        );

        $this->addArgument(
            'api_version',
            InputArgument::REQUIRED,
            'Версия разрабатываемого API, например v1.0.'
        );

        $this->addOption(
            'type',
            null,
            InputOption::VALUE_REQUIRED,
            'Тип контроллера - a|api, ac|api-crud',
            'api-crud'
        );
    }

    public function initGeneratorParams(): void
    {
        // Под Hyperf: контроллеры по умолчанию в App\Controller
        $this->default_generator_namespace = 'App\\Controller';
        $this->default_generator_dir = 'Controller';
    }

    public function handle(): int
    {
        $this->initGeneratorParams();

        /** @var string $controllerNameRaw */
        $controllerNameRaw = (string) $this->input->getArgument('controller_name');
        if (! $this->isValidClassName($controllerNameRaw)) {
            $this->output->error('Недопустимое имя контроллера. Разрешены буквы/цифры/подчёркивание и "/" для вложенности.');

            return self::FAILURE;
        }

        if (! $this->resolveStubByType()) {
            return self::FAILURE;
        }

        /** @var string $prefixRaw */
        $prefixRaw = (string) $this->input->getArgument('api_version');
        $this->api_prefix_raw = $prefixRaw; // идёт в URL без апкаса

        // Мягкая валидация формата версии (v1 или v1.2.3 и т.д.)
        if (! preg_match('/^v\d+(?:\.\d+)*$/i', $prefixRaw)) {
            $this->output->warning("Нестандартный формат версии API: {$prefixRaw}. Ожидается что-то вроде v1 или v1.2.");
        }

        // Для namespace используем нормализованную форму V1_0
        $prefixForNamespace = strtoupper(str_replace('.', '_', $prefixRaw));
        $this->default_generator_namespace .= '\\Api\\' . $prefixForNamespace;
        $this->default_generator_dir .= '/Api/' . $prefixForNamespace;

        $this->initGenerator($controllerNameRaw);

        // ВАЖНО: модель теперь парсим ВСЕГДА — нужна и для api, и для api-crud (префикс URL).
        /** @var string $modelNameRaw */
        $modelNameRaw = (string) $this->input->getArgument('model_name');
        if (! $this->isValidClassName($modelNameRaw)) {
            $this->output->error('Недопустимое имя модели. Разрешены буквы/цифры/подчёркивание и "/" для вложенности.');

            return self::FAILURE;
        }

        // В Hyperf по умолчанию модели лежат в App\Model
        $this->modelMeta = $this->getMetaFromClassName(
            $modelNameRaw,
            'App\\Model',
            'Model'
        );

        if ($this->checkClassExist($this->class_path)) {
            $this->output->warning("Контроллер [{$this->class_name}] уже существует.");

            return self::FAILURE;
        }

        if (! $this->createClass()) {
            $this->output->error("Не получилось создать контроллер ({$this->class_path}).");

            return self::FAILURE;
        }

        $this->output->info("Контроллер [{$this->class_name}] создан успешно.");

        return self::SUCCESS;
    }

    public function makeClassData(): string
    {
        $data = (string) @file_get_contents($this->currentStub);

        // Общие плейсхолдеры
        $data = $this->render($data, [
            '{{ controller_namespace }}' => $this->class_namespace,
            '{{ controller_name }}' => $this->class_name,
            '{{ prefix }}' => $this->api_prefix_raw, // сырая версия для URL
        ]);

        // model_name_lower нужен теперь в обоих шаблонах
        $modelName = $this->modelMeta['name'] ?? null;
        if ($modelName !== null && $modelName !== '') {
            $model_name_lower = helper_string_snake((string) helper_string_plural($modelName));

            $data = $this->render($data, [
                '{{ model_name_lower }}' => $model_name_lower,
                '{{ model_namespace }}' => $this->modelMeta['namespace'] ?? 'App\\Model',
                '{{ model_name }}' => $this->modelMeta['name'] ?? 'Model',
            ]);
        }

        return $data;
    }

    private function resolveStubByType(): bool
    {
        /** @var string $typeRaw */
        $typeRaw = (string) $this->input->getOption('type');
        $type = $this->getNormalizedType();

        if (! isset(self::AVAILABLE_STUBS[$type])) {
            $this->output->error('Неизвестный тип контроллера: ' . $typeRaw);

            return false;
        }

        $stub = $this->stubPath(self::AVAILABLE_STUBS[$type]);
        if (! file_exists($stub)) {
            $this->output->error("Заготовка контроллера типа '{$type}' не найдена: {$stub}");

            return false;
        }

        $this->currentStub = $stub;

        return true;
    }

    private function normalizeType(string $t): string
    {
        return match ($t) {
            'a' => 'api',
            'ac' => 'api-crud',
            default => $t,
        };
    }

    private function getNormalizedType(): string
    {
        if ($this->normalizedType === null) {
            /** @var string $typeRaw */
            $typeRaw = (string) $this->input->getOption('type');
            $this->normalizedType = $this->normalizeType($typeRaw);
        }

        return $this->normalizedType;
    }

    private function isCrud(string $check): bool
    {
        // После нормализации достаточно строгого сравнения
        return $check === 'api-crud';
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
