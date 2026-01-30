<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Console\Commands\Generator;

use Hyperf\Command\Annotation\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;

#[Command(name: 'lighty:generate-model', description: 'Generate model class')]
final class ModelGenerator extends BaseGenerator
{
    protected string $description = 'Метод для генерации моделей с использованием предлагаемой пакетом архитектуры.';

    /**
     * Сейчас реально используем только base, остальные зарезервированы.
     * @var array<string,string>
     */
    private const AVAILABLE_STUBS = [
        'authenticatable' => 'model.authenticatable.stub', // резерв
        'loggingable' => 'model.loggingable.stub',     // резерв
        'base' => 'model.base.stub',            // используем
    ];

    // Новый stub для файла config/events/{{Model}}.php
    private const STUB_EVENTS_CONFIG = 'events.config.stub';

    private bool $withoutEvents = false;

    public function __construct()
    {
        parent::__construct('lighty:generate-model');
    }

    public function configure(): void
    {
        $this->addArgument(
            'model_name',
            InputArgument::REQUIRED,
            'Название модели. Используйте слэш (/) для вложенности.'
        );

        $this->addOption(
            'type',
            null,
            InputOption::VALUE_REQUIRED,
            'Тип модели - l|loggingable, a|authenticatable, b|base',
            'base'
        );

        // По умолчанию генерим С событиями (конфигом). Флаг отключает генерацию событийного конфига.
        $this->addOption(
            'without-events',
            null,
            InputOption::VALUE_NONE,
            'Не генерировать файл config/events/{{Model}}.php и не обновлять маппинг.'
        );
    }

    public function initGeneratorParams(): void
    {
        $this->default_generator_namespace = 'App\\Model';
        $this->default_generator_dir = 'Model';
    }

    public function handle(): int
    {
        $this->initGeneratorParams();

        /** @var string $modelNameRaw */
        $modelNameRaw = $this->input->getArgument('model_name');
        if (! $this->isValidClassName($modelNameRaw)) {
            $this->output->error('Недопустимое имя модели. Разрешены буквы/цифры/подчёркивание и "/" для вложенности.');

            return self::FAILURE;
        }

        $this->withoutEvents = (bool) $this->input->getOption('without-events');

        if (! $this->resolveStubByType()) {
            return self::FAILURE;
        }

        $this->initGenerator($modelNameRaw);

        if ($this->checkClassExist($this->class_path)) {
            $this->output->warning("Модель [{$this->class_name}] уже существует.");

            return self::FAILURE;
        }

        if (! $this->createClass()) {
            $this->output->warning("Не получилось создать модель ({$this->class_path}).");

            return self::FAILURE;
        }

        if (! $this->withoutEvents) {
            $okConfig = $this->generateEventsConfigFile($this->class_name);
            $okMap = $this->ensureModelEventsMap($this->class_namespace . '\\' . $this->class_name, $this->class_name);

            if (! $okConfig || ! $okMap) {
                $this->output->warning('Сгенерирована модель, но не удалось корректно создать конфиг событий и/или маппинг.');

                return self::FAILURE;
            }
        }

        $this->output->info("Модель [{$this->class_name}] создана успешно." . ($this->withoutEvents ? '' : ' Создан config/events и обновлён маппинг.'));

        return self::SUCCESS;
    }

    public function makeClassData(): string
    {
        // Только чистый класс из stub — без вставки boot() и без event-классов.
        $data = (string) @file_get_contents($this->stubPath(self::AVAILABLE_STUBS['base']));
        $data = $this->render($data, [
            '{{ model_namespace }}' => $this->class_namespace,
            '{{ model_name }}' => $this->class_name,
        ]);

        return $data;
    }

    private function resolveStubByType(): bool
    {
        /** @var string $typeRaw */
        $typeRaw = $this->input->getOption('type');
        $type = $this->normalizeType($typeRaw);

        if (! isset(self::AVAILABLE_STUBS[$type])) {
            $this->output->warning('Неизвестный тип модели: ' . $typeRaw);

            return false;
        }

        if ($type !== 'base') {
            $this->output->warning("Тип '{$type}' пока не поддерживается в Hyperf-версии генератора.");

            return false;
        }

        $stub = $this->stubPath(self::AVAILABLE_STUBS[$type]);
        if (! file_exists($stub)) {
            $this->output->warning("Заготовка модели типа '{$type}' не найдена: {$stub}");

            return false;
        }

        return true;
    }

    private function normalizeType(string $t): string
    {
        return match ($t) {
            'l' => 'loggingable',
            'a' => 'authenticatable',
            'b' => 'base',
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

    /**
     * Создаёт config/events/{{Model}}.php из шаблона STUB_EVENTS_CONFIG.
     * Имя файла — ровно {{Model}}, без изменения регистра.
     */
    private function generateEventsConfigFile(string $model): bool
    {
        /** @var string $base */
        $base = (\defined('BASE_PATH') ? BASE_PATH : getcwd());
        $dir = $base . '/config/events';

        if (! $this->createDirectory($dir)) {
            $this->output->error('Не удалось создать директорию: ' . $dir);

            return false;
        }
        $model_snake = $this->toSnakeCase($model);
        $path = $dir . '/' . $model_snake . '.php';

        if (file_exists($path)) {
            // не перезаписываем существующий конфиг
            $this->output->comment("Файл конфигурации событий уже существует: {$path}");

            return true;
        }

        $tpl = (string) @file_get_contents($this->stubPath(self::STUB_EVENTS_CONFIG));
        if ($tpl === '') {
            $this->output->error('Шаблон конфигурации событий пустой или не найден: ' . $this->stubPath(self::STUB_EVENTS_CONFIG));

            return false;
        }

        $code = $this->render($tpl, [
            '{{ model_name }}' => $model,
        ]);

        $ok = @file_put_contents($path, $code, LOCK_EX);
        if ($ok === false) {
            $this->output->error('Не удалось записать файл конфигурации событий: ' . $path);

            return false;
        }

        $this->output->info("Создан файл конфигурации событий: {$path}");

        return true;
    }

    /**
     * Обеспечивает наличие маппинга в config/autoload/model_events.php:
     * '\App\Model\{{Model}}::class' => '{{Model}}'
     */
    private function ensureModelEventsMap(string $fqcn, string $key): bool
    {
        /** @var string $base */
        $base = (\defined('BASE_PATH') ? BASE_PATH : getcwd());
        $path = $base . '/config/autoload/model_events.php';

        $key = $this->toSnakeCase($key);

        if (! file_exists($path)) {
            $this->output->comment('Файл model_events.php не найден — выполняется публикация...');

            $process = new Process(['php', 'bin/hyperf.php', 'vendor:publish',
                'khazhinov/hyperf-lighty', '--id', 'model-events-map']);
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->output->error("Ошибка при публикации model-events.php:\n" . $process->getErrorOutput());

                return false;
            }

            $this->output->writeln(trim($process->getOutput()));

            if (! file_exists($path)) {
                $this->output->error('Не удалось опубликовать model_events.php автоматически. Создай вручную: php bin/hyperf.php vendor:publish model-events-map');

                return false;
            }

            $this->output->info('model_events.php успешно опубликован.');
        }

        $code = (string) @file_get_contents($path);
        if ($code === '') {
            $this->output->error('Файл model_events.php пуст или нечитабелен.');

            return false;
        }

        if (strpos($code, "\\{$fqcn}::class => '{$key}'") !== false) {
            $this->output->comment("Маппинг уже существует в {$path}");

            return true;
        }

        $pattern = '/([\'"]map[\'"]\s*=>\s*\[\s*)(\n)?(\s*)(.*?)(\s*\],)/s';
        if (preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
            $insert = PHP_EOL . "            \\{$fqcn}::class => '{$key}'," . PHP_EOL . "        ";
            $new = substr($code, 0, $m[1][1] + strlen($m[1][0])) . $insert . substr($code, $m[1][1] + strlen($m[1][0]));
            $ok = @file_put_contents($path, $new, LOCK_EX);

            if ($ok !== false) {
                $this->output->info("Добавлен маппинг: \\{$fqcn} => '{$key}'");

                return true;
            }

            $this->output->error("Не удалось записать {$path}");

            return false;
        }

        $this->output->warning("Не удалось вставить маппинг в {$path} — структура файла нестандартная.");

        return false;
    }

    private function stubPath(string $file): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'Stubs' . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Преобразует CamelCase в snake_case.
     * Company -> company
     * ShosSection -> shos_section
     * ShoppingCartCatalogItem -> shopping_cart_catalog_item
     */
    private function toSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }
}
