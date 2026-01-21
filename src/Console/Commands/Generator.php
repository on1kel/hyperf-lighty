<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Console\Commands;

use Hyperf\Command\Annotation\Command;
use On1kel\HyperfLighty\Console\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[Command(name: 'lighty:generator', description: 'Комплексная генерация сущностей (model/resource/controller/requests/route)')]
final class Generator extends BaseCommand
{
    protected string $description = 'Метод для комплексной генерации базовых сущностей с использованием предлагаемой пакетом архитектуры.';

    public function __construct()
    {
        parent::__construct('lighty:generator');
    }

    public function configure(): void
    {
        $this->addArgument(
            'model_name',
            InputArgument::REQUIRED,
            'Наименование модели. Используйте слэш (/) для вложенности.'
        );

        $this->addArgument(
            'api_version',
            InputArgument::REQUIRED,
            'Версия разрабатываемого API, например v1.0.'
        );

        $this->addOption(
            'migration',
            null,
            InputOption::VALUE_NONE,
            'Флаг необходимости генерации миграции.'
        );
    }

    public function handle(): int
    {
        /** @var string $modelName */
        $modelName = (string) $this->input->getArgument('model_name');
        /** @var string $apiVersion */
        $apiVersion = (string) $this->input->getArgument('api_version');
        $withMigration = (bool) $this->input->getOption('migration');

        // 1) Model
        $this->call('lighty:generate-model', [
            'model_name' => $modelName,
        ]);

        // 2) Resources (Single + Collection)
        $this->call('lighty:generate-resource', [
            'resource_name' => $modelName,
            'model_name' => $modelName,
            '--type' => 'single',
        ]);

        $this->call('lighty:generate-resource', [
            'resource_name' => $modelName,
            'model_name' => $modelName,
            '--type' => 'collection',
        ]);

        // 3) Controller (CRUD)
        $this->call('lighty:generate-controller', [
            'controller_name' => $modelName . '/' . $modelName . 'CRUDController',
            'model_name' => $modelName,
            'api_version' => $apiVersion,
            '--type' => 'ac',
        ]);

        // 4) Requests (Store / Update)
        $this->call('lighty:generate-request', [
            'request_name' => $modelName . '/' . $modelName . 'StoreRequest',
        ]);

        $this->call('lighty:generate-request', [
            'request_name' => $modelName . '/' . $modelName . 'UpdateRequest',
        ]);

        // 5) Migration (опционально)
        if ($withMigration) {
            $this->call('lighty:generate-migration', [
                'table' => helper_string_plural(helper_string_snake($modelName)),
            ]);
        }

        $this->output->success('Комплексная генерация завершена.');

        return self::SUCCESS;
    }
}
