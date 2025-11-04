<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Console\Commands\Generator;

use Hyperf\Command\Annotation\Command;
use On1kel\HyperfLighty\Console\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;

#[Command(name: 'lighty:generate-migration', description: 'Generate migration file')]
final class MigrationGenerator extends BaseCommand
{
    private string $current_stub;
    protected string $description = 'Метод для генерации миграции с использованием предлагаемой пакетом архитектуры.';

    /**
     * Карта доступных стабов
     * @var array<string, string>
     */
    private array $available_stubs = [
        'migration' => 'migration.api.stub',
    ];

    public function __construct()
    {
        parent::__construct('lighty:generate-migration');
    }

    public function configure(): void
    {
        $this->addArgument('table', InputArgument::REQUIRED, 'Название таблицы');
    }

    public function handle(): int
    {
        if (! $this->checkStub()) {
            $this->output->warning('Заготовка для миграции не найдена.');

            return self::FAILURE;
        }
        /** @var string $table */
        $table = $this->input->getArgument('table');
        $name = sprintf('create_%s_table', $table);

        $data = $this->makeClassData($table);
        $path = $this->getPath($name, database_path());

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            $this->output->error('Не удалось создать директорию для миграции: ' . $dir);

            return self::FAILURE;
        }

        $saved = @file_put_contents($path, $data, LOCK_EX);
        if ($saved === false) {
            $this->output->error('Не удалось записать файл миграции: ' . $path);

            return self::FAILURE;
        }

        $this->output->success(sprintf('Миграция [%s] создана: %s', $name, $path));

        return self::SUCCESS;
    }

    protected function getDatePrefix(): string
    {
        return date('Y_m_d_His');
    }

    protected function getPath(string $name, string $path): string
    {
        return rtrim($path, '/\\') . '/' . $this->getDatePrefix() . '_' . $name . '.php';
    }

    private function checkStub(): bool
    {
        $this->current_stub = __DIR__ . DIRECTORY_SEPARATOR . 'Stubs' . DIRECTORY_SEPARATOR . $this->available_stubs['migration'];

        return file_exists($this->current_stub);
    }

    private function makeClassData(string $table): string
    {
        $data = (string) @file_get_contents($this->current_stub);

        return str_ireplace('{{ table }}', $table, $data);
    }
}
