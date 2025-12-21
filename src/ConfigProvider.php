<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty;

final class ConfigProvider
{
    public function __invoke(): array
    {
        $generatedCommands = [
            \On1kel\HyperfLighty\Console\Commands\Generator\MigrationGenerator::class,
             \On1kel\HyperfLighty\Console\Commands\Generator\ControllerGenerator::class,
            \On1kel\HyperfLighty\Console\Commands\Generator\ModelGenerator::class,
             \On1kel\HyperfLighty\Console\Commands\Generator\RequestGenerator::class,
             \On1kel\HyperfLighty\Console\Commands\Generator\ResourceGenerator::class,
             \On1kel\HyperfLighty\Console\Commands\Generator::class,
        ];

        /**
         * 1) Подхватить все конфиги из config/events/*.php
         *    Каждый файл попадёт в конфиг как: model_events.payload.<basename> => require <file>
         *    Пример: config/events/example.php => model_events.payload.example
         */
        $basePath = \defined('BASE_PATH') ? \BASE_PATH : '';
        $eventsDir = $basePath !== '' ? $basePath . '/config/events' : null;
        $payload = [];

        if ($eventsDir && \is_dir($eventsDir)) {
            foreach (\glob($eventsDir . '/*.php') ?: [] as $file) {
                $key = \basename($file, '.php'); // например, 'example'
                // грузим массив событий для модели
                $payload["model_events.payload.{$key}"] = require $file;
            }
        }

        /**
         * 2) Команды пакета (добавляем — без удаления ваших)
         *    Если валидатор не нужен — просто закомментируй строку.
         */
        $packageCommands = [
            // Валидатор конфигов событий (опционально)
            \On1kel\HyperfLighty\Domain\Command\ValidateEventsConfigCommand::class,
        ];

        /**
         * 3) Публикация артефактов (не удаляем ваши, а дополняем)
         */
        $packagePublish = [
            [
                'id' => 'lighty',
                'description' => 'Publish core config',
                'source' => __DIR__ . '/../config/lighty.php',
                'destination' => \BASE_PATH . '/config/autoload/lighty.php',
            ],
            [
                'id' => 'model-events-map',
                'description' => 'Publish model events map (model → events key)',
                'source' => __DIR__ . '/../config/model_events.php',
                'destination' => \BASE_PATH . '/config/autoload/model_events.php',
            ],
            [
                'id' => 'model-events-example',
                'description' => 'Publish example events config (example)',
                'source' => __DIR__ . '/../config/events/example.php',
                'destination' => \BASE_PATH . '/config/events/example.php',
            ],
            [
                'id' => 'storage-languages',
                'description' => 'Publish storage languages (en/ru) with errors.php',
                'source' => __DIR__ . '/../storage/languages',
                'destination' => \BASE_PATH . '/storage/languages',
            ],
        ];

        $annotationScan = [
            'paths' => [
                // ваши пути оставляем как есть — они у вас закомментированы
                // __DIR__ . '/Console',
                __DIR__ . '/Listeners', // здесь лежит UniversalModelEventsRouter
            ],
        ];

        /**
         * 5) DI. Добавим зависимости пакета, не трогая ваши.
         *    Если используешь другие пространства имён — поправь.
         */
        $packageDependencies = [
            \On1kel\HyperfLighty\Domain\Support\AfterCommitManager::class => \On1kel\HyperfLighty\Domain\Support\AfterCommitManager::class,
            \On1kel\HyperfLighty\Domain\Support\TransactionRunner::class => \On1kel\HyperfLighty\Domain\Support\TransactionRunner::class,
            \On1kel\HyperfLighty\OpenApi\Complexes\Reflector\ModelReflector::class => \On1kel\HyperfLighty\OpenApi\Complexes\Reflector\ModelReflector::class,
            \On1kel\HyperfLighty\OpenApi\Complexes\Reflector\IdeHelperModelsReaderInterface::class => \On1kel\HyperfLighty\OpenApi\Complexes\Reflector\IdeHelperModelsReader::class,

        ];

        /**
         * 6) Корневая секция model_events: map оставляем пустым — проект перекроет publish’ем.
         */
        $rootModelEvents = [
            'model_events' => [
                'map' => [
                    // \App\Model\example::class => 'example',
                ],
            ],
        ];

        return [
            // === DI (ваши + пакетные) ===
            'dependencies' => array_merge([
                // … ваши биндинги, если появятся
            ], $packageDependencies),

            // === Аннотации (оставляем вашу логику, просто добавляем paths) ===
            'annotations' => [
                'scan' => $annotationScan,
            ],

            // === Команды (ваши + пакетные) ===
            'commands' => array_merge($generatedCommands, $packageCommands),

            // === Публикация (ваши + пакетные) ===
            'publish' => array_merge([
                // ваши publish-группы (сейчас закомментированы)
                // [
                //     'id' => 'lighty-config',
                //     'description' => 'Publish HyperfLighty config',
                //     'source' => __DIR__ . '/../config/lighty.php',
                //     'destination' => BASE_PATH . '/config/autoload/lighty.php',
                // ],
                // [
                //     'id' => 'lighty-stubs',
                //     'description' => 'Publish HyperfLighty stubs',
                //     'source' => __DIR__ . '/Console/Commands/Generator/Stubs',
                //     'destination' => BASE_PATH . '/app/Console/Commands/Generator/Stubs',
                // ],
            ], $packagePublish),

            // === Корневой конфиг для карты + сгенерированный payload из config/events/*.php ===
            ...$rootModelEvents,
            ...$payload,
        ];
    }
}
