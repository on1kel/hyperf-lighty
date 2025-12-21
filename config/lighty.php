<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'models' => [
        'uuid' => [
            'version' => (int) env('MODEL_UUID_VERSION', 4),
        ],
        'user_model_class' => 'App\Models\User',
    ],
    'auth' => [
        'guard' => 'sanctum',
    ],
    'export' => [
        'xlsx' => [
            'styles' => [
                // Стилизация результирующего документа XLSX
                // https://docs.laravel-excel.com/3.1/exports/column-formatting.html#styling
                // Применить к первой строке жирный шрифт
                1 => ['font' => ['bold' => true]],
                // Применение стилей по координате
                //'B2' => ['font' => ['italic' => true]],
                // Применение стилей по столбцу
                //'C' => ['font' => ['size' => 16]],
            ],
        ],
        'csv' => [
            // Настройки для результирующего документа CSV
            // https://docs.laravel-excel.com/3.1/exports/settings.html#custom-csv-settings
            // 'delimiter' => ';',
            'input_encoding' => 'UTF-8',
            'output_encoding' => 'windows-1251', // Для корректного отображения кириллицы в Excel
        ],
    ],
    'ide_helper' => [
        // Включить интеграцию с _ide_helper_models.php
        'enabled' => env('LIGHTY_IDE_HELPER_ENABLED', true),

        // Путь к файлу-генератору friendsofhyperf/ide-helper
        'models_file' => env('LIGHTY_IDE_HELPER_MODELS_FILE', '_ide_helper_models.php'),

        // Кэш-ключ и TTL для разобранных структур
        'cache_key' => 'lighty.ide_helper.models.parsed',
        'cache_ttl' => 86400, // 1 день
    ],
    'exceptions' => [
        'allowed' => [
            // Пример:
            // \App\Exception\DomainException::class,
            // \Hyperf\Validation\ValidationException::class,
        ],

        // Сообщение по умолчанию (ключ перевода или текст).
        'fallback_message_key' => 'errors.something_went_wrong',
        'fallback_message_text' => 'Something went wrong.',

        // Если true — даже для "неразрешённых" отдаём errorData (опасно).
        'expose_unknown_error_details' => env('EXPOSE_UNKNOWN_ERROR_DETAILS', false),
    ]
];
