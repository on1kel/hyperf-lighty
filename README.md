# Hyperf Lighty

> **Набор инструментов для быстрого и стандартизированного создания REST API на базе [Hyperf](https://hyperf.io/).**  
> Предоставляет модульную архитектуру для CRUD-операций, валидации, событий моделей и генерации OpenAPI-документации.

---

## Основные возможности

- **Быстрая генерация CRUD-эндпоинтов** — автоматическое создание контроллеров, ресурсов и сервисов.
- **Единая архитектура слоёв** — строгая структура `Controller → Service → Model`.
- **Встроенная валидация и ресурсы** — использует стандартные механизмы `Hyperf\Validation` и `Hyperf\Resource`.
- **Асинхронные очереди и события** — поддержка `hyperf/async-queue` и гибкое управление событиями моделей.
- **Документация API из кода** — интеграция с [on1kel/hyperf-fly-docs](https://packagist.org/packages/on1kel/hyperf-fly-docs).
- **Расширяемость и переопределение** — возможность легко подключать собственные адаптеры, трейты и кастомные события.
- **Минимальная зависимость от фреймворка** — пакет можно использовать как библиотеку.

---

## Установка

```bash
composer require on1kel/hyperf-lighty
```

> Требуется PHP 8.1+ и Hyperf 3.1+

---

## Быстрый старт

### 1. Подключение конфигурации

После установки зарегистрируйте конфиг-провайдер в вашем `config/autoload/dependencies.php` (обычно добавляется автоматически):

```php
return [
    \On1kel\HyperfLighty\ConfigProvider::class,
];
```

### 2. Публикация конфигураций

```bash
php bin/hyperf.php vendor:publish on1kel/hyperf-lighty
```

Будут созданы файлы:
- `config/autoload/model_events.php`
- `config/events/attendance.php` (пример событий моделей)

### 3. Создание CRUD-контроллера

```bash
php bin/hyperf.php lighty:generate User V1_0
```

Будут автоматически сгенерированы:
- `App/Http/Controllers/UserController.php`
- `App/Services/UserService.php`
- `App/Models/User.php`
- ресурсы и валидации


### 4. Генерация файла `_ide_helper_models.php`

Для корректной работы **автоматической генерации OpenAPI-документации** пакет требует наличия актуального файла `_ide_helper_models.php`, содержащего метаданные всех моделей проекта.

#### Требования
- Установленный пакет [`friendsofhyperf/ide-helper`](https://packagist.org/packages/friendsofhyperf/ide-helper)

#### Установка
```bash
composer require --dev friendsofhyperf/ide-helper
```

#### Генерация моделей
После установки выполните команду:
```bash
php bin/hyperf.php ide-helper:model
```

В результате будет создан (или обновлён) файл:
```
_ide_helper_models.php
```

Этот файл обеспечивает:
- корректную работу IDE-подсказок (PhpStorm, VSCode и др.);
- автоматическую генерацию схем моделей для OpenAPI-документации;
- улучшенное автодополнение в коде при работе с моделями.

> **Совет:** рекомендуется добавить команду генерации в ваши dev-скрипты Composer, например:
> ```json
> {
>   "scripts": {
>       "post-install-cmd": [
>           "@php bin/hyperf.php ide-helper:model"
>       ]
>   }
> }
> ```



---

## Архитектура

```
src/
 ├── Console/
 │   └── Commands/Generator/...       # Генераторы кода
 ├── Domain/
 │   └── Listeners/...                # Слушатели событий моделей
 ├── Http/
 │   ├── Controllers/...
 │   └── Resources/...
 ├── Services/
 │   ├── CRUD/...                     # CRUD-операции
 │   └── Encrypter.php                # Шифрование на Sodium
 └── OpenApi/...
```

### 4. Создание _ide_helper_models.php

Для полноценной работы пакета необходимо установленный пакет friendsofhyperf/ide-helper и выполненная команда для полноценной работы автоматической генерации OpenApi документации
```bash
php bin/hyperf.php ide-helper:model
```

Ниже — **продуктовое, аккуратное описание**, без учебного тона и лишней техники, с акцентом на **зачем**, **что даёт**, **как использовать в проде**.

---

## 5. Разделение процессов по ролям (для запуска в отдельных контейнерах)

Пакет **`on1kel/hyperf-lighty`** вводит единый, декларативный механизм управления процессами Hyperf, предназначенный для **чёткого разделения ролей приложения** и безопасного деплоя в Docker/Kubernetes.

Цель:

* запускать разные группы процессов в **разных контейнерах** (`api`, `queue`, `cron`, и т.д.);
* исключить дублирование cron/consumer при горизонтальном масштабировании;
* сохранить **один Docker-образ** и управлять поведением через переменные окружения.

---

### Публикация конфигурации

Опубликуйте конфигурацию пакета:

```bash
php bin/hyperf.php vendor:publish on1kel/hyperf-lighty
```

После публикации конфиг пакета становится **единственным источником истины** для определения:

* какие процессы существуют в приложении;
* в каких ролях они могут запускаться.

---

### Замена стандартного `processes.php`

Замените стандартный `config/autoload/processes.php` на версию, предоставляемую пакетом.

Этот файл:

* читает активные роли из `APP_ROLES`;
* фильтрует процессы на основе атрибутов ролей;
* возвращает **строго тот набор процессов**, который допустим для текущего контейнера.

```php
<?php
declare(strict_types=1);

use Hyperf\Di\ReflectionManager;
use On1kel\HyperfLighty\Attributes\Process\DeployRoles;

$rolesEnv = env('APP_ROLES', 'api');
$enabledRoles = array_values(array_filter(array_map('trim', explode(',', $rolesEnv))))
    ?: ['api'];

$registry = require __DIR__ . '/process_registry.php';

$enabled = [];

foreach ($registry as $key => $value) {
    // ===== Вариант 1: Явная карта ролей =====
    if (is_string($key) && is_array($value)) {
        if (array_intersect($enabledRoles, $value)) {
            $enabled[] = $key;
        }
        continue;
    }

    // ===== Вариант 2: Класс с атрибутом или универсальный =====
    $class = $value;

    $ref = ReflectionManager::reflectClass($class);
    $attrs = $ref->getAttributes(DeployRoles::class);

    // Нет атрибута → универсальный процесс
    if ($attrs === []) {
        $enabled[] = $class;
        continue;
    }

    /** @var DeployRoles $roles */
    $roles = $attrs[0]->newInstance();
    if (array_intersect($enabledRoles, $roles->roles)) {
        $enabled[] = $class;
    }
}

return $enabled;
```

---

### Назначение ролей процессам

Для указания ролей используется атрибут **`DeployRoles`**.
Процесс сам декларативно описывает, **в каких ролях он допустим**.

#### Пример: процесс cron

```php
<?php

namespace On1kel\HyperfLighty\Process;

use Hyperf\Crontab\Process\CrontabDispatcherProcess;
use On1kel\HyperfLighty\Attributes\Process\DeployRoles;

#[DeployRoles(['cron'])]
final class CronProcess extends CrontabDispatcherProcess {}
```

Такой процесс будет запущен **только** в контейнере с:

```env
APP_ROLES=cron
```

---

### Переопределение стандартных процессов Hyperf

Пакет предоставляет собственные реализации стандартных процессов Hyperf с уже назначенными ролями.

#### Cron

```php
#[DeployRoles(['cron'])]
final class CronProcess extends CrontabDispatcherProcess {}
```

#### Queue consumers

```php
#[DeployRoles(['queue'])]
final class QueueConsumerProcess extends ConsumerProcess {}
```

Это позволяет:

* использовать стандартные компоненты Hyperf;
* контролировать их запуск **без условий и if-логики**;
* централизованно управлять поведением через `APP_ROLES`.

---

### Поддержка процессов из внешних пакетов (`final`, без атрибутов)

Не все процессы можно или целесообразно помечать атрибутами ролей:

* класс объявлен как `final`;
* процесс находится во внешнем пакете;
* пакет не должен знать о деплое и инфраструктуре.

Для таких случаев `process_registry.php` поддерживает **явное назначение ролей**.

```php
<?php
declare(strict_types=1);

use Cubekit\Kafka\Hyperf\Process\InboxKafkaConsumerProcess;
use Cubekit\Kafka\Hyperf\Process\OutboxKafkaConsumerProcess;
use On1kel\HyperfLighty\Process\CronProcess;
use On1kel\HyperfLighty\Process\QueueConsumerProcess;

return [
    // Явное назначение ролей (final / внешние пакеты)
    InboxKafkaConsumerProcess::class => ['kafka'],
    OutboxKafkaConsumerProcess::class => ['kafka'],

    // Процессы с атрибутами DeployRoles
    CronProcess::class,
    QueueConsumerProcess::class,
];
```

Таким образом:

* пакет Kafka **не зависит** от `hyperf-lighty`;
* роли определяются **исключительно на уровне приложения**;
* деплой остаётся декларативным и прозрачным.

Приоритет правил:

1. Явное указание ролей в `process_registry.php`
2. Атрибут `DeployRoles`
3. Отсутствие ролей → процесс считается универсальным

---

### Как это используется в контейнерах

Один и тот же Docker-образ, разные роли:

```yaml
hyperf-api:
  environment:
    APP_ROLES: api

hyperf-queue:
  environment:
    APP_ROLES: queue

hyperf-cron:
  environment:
    APP_ROLES: cron
```

Каждый контейнер поднимает **только свой набор процессов**, без риска дублирования.

---

## 6. Единый логгер и вывод в stdout (prod-ready)

`on1kel/hyperf-lighty` предоставляет готовое решение для **унификации логирования** в Hyperf-приложениях и корректной работы в Docker / Kubernetes.

### Проблема, которую решает пакет

По умолчанию в Hyperf существуют **два разных источника логов**:

* логи приложения — через `monolog` и `config/autoload/logger.php`;
* системные логи Hyperf (startup, workers, signals) — через `StdoutLogger`, использующий `print_r()`.

Это приводит к проблемам в проде:

* смешанные форматы логов;
* отсутствие структурированных данных;
* невозможность нормально парсить stdout лог-агрегаторами (Loki / ELK / CloudWatch);
* “мусорные” строки в логах при инцидентах.

---

### Что делает Hyperf Lighty

Пакет предоставляет **production-ready реализацию `StdoutLoggerFactory`**, которая:

* подменяет стандартный `StdoutLogger` Hyperf;
* перенаправляет **все системные логи фреймворка** в Monolog;
* использует **единый формат и уровни логирования**;
* полностью совместима с `config/autoload/logger.php`.

В результате:

* **все логи** (и приложения, и фреймворка) проходят через Monolog;
* stdout/stderr становятся управляемыми и предсказуемыми;
* логирование готово к прод-эксплуатации.

---

### Подключение StdoutLoggerFactory

В каждом приложении биндинг выполняется **один раз** — в `config/autoload/dependencies.php`:

```php
return [
    \Hyperf\Contract\StdoutLoggerInterface::class
        => \On1kel\HyperfLighty\Logger\StdoutLoggerFactory::class,
];
```

После этого:

* Hyperf перестаёт писать `print_r()` в stdout;
* все системные сообщения логируются через Monolog (channel `sys`).

---

### Настройка формата логов (`logger.php`)

`StdoutLoggerFactory` **не заменяет** конфигурацию логов — она лишь направляет вывод в Monolog.
Формат, уровни и handlers задаются стандартным способом через `config/autoload/logger.php`.

Рекомендуемая схема:

* **dev / local** — человекочитаемые логи (LineFormatter, с цветами);
* **prod** — структурированные JSON-логи.

Пример минимальной конфигурации:

```php
<?php
declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

return [
    'default' => [
        'handler' => [
            'class' => StreamHandler::class,
            'constructor' => [
                'stream' => 'php://stdout',
                'level' => Level::Info,
            ],
        ],
        'formatter' => [
            'class' => JsonFormatter::class,
            'constructor' => [
                'appendNewline' => true,
            ],
        ],
    ],
];
```

> ⚠️ В Docker/Kubernetes **рекомендуется использовать только stdout/stderr**, без файловых логов внутри контейнера.

---

### Каналы логирования

Рекомендуется использовать **фиксированные каналы**, а не динамические имена:

* `sys` — системные логи Hyperf;
* `http` — HTTP-запросы;
* `queue` — очереди;
* `kafka.*` — Kafka consumers;
* `cron` — cron-задачи.

❗ **Не используйте request_id или user_id в качестве имени канала** — это приводит к утечкам памяти, так как `LoggerFactory` кеширует логгеры.

Контекст (request_id, trace_id и т.п.) должен передаваться через `context` или processors.

---

## Безопасность

- Используется расширение `ext-sodium` для безопасного шифрования (`sodium_crypto_secretbox`).
- Все ключи и токены рекомендуется хранить в `.env`.
- Поддержка строгой типизации (`declare(strict_types=1)`).

---

## Index Action (Поиск и аналитика)

Метод `POST /search` поддерживает расширенные возможности поиска, фильтрации, сортировки и аналитики.

### Структура запроса

```json
{
    "select": [...],
    "where": [...],
    "order": [...],
    "join": [...],
    "group_by": [...],
    "with": {...},
    "limit": 10,
    "page": 1,
    "paginate": true,
    "return_type": "resource",
    "export": {...}
}
```

### Select (Выбор полей)

Позволяет указать конкретные поля для выборки и применять агрегации.

```json
{
    "select": [
        {"column": "*"},
        {"column": "id"},
        {"column": "name", "alias": "user_name"},
        {"column": "id", "aggregation": "count", "alias": "total"}
    ]
}
```

| Параметр | Тип | Описание |
|----------|-----|----------|
| `column` | string | Имя столбца. Может содержать таблицу: `table.column`. Используйте `*` для всех полей. |
| `aggregation` | string? | Функция агрегации: `count`, `sum`, `avg`, `min`, `max` |
| `alias` | string? | Псевдоним для столбца в ответе |

**Важно:** При использовании агрегаций ответ возвращается как сырые данные, а не через ресурс.

### Where (Фильтрация)

Поддерживает одиночные условия и группы с логическими операторами.

```json
{
    "where": [
        {"column": "status", "operator": "=", "value": "active"},
        {"column": "created_at", "operator": ">=", "value": "2024-01-01"},
        {
            "type": "group",
            "boolean": "or",
            "group": [
                {"column": "role", "value": "admin"},
                {"column": "role", "value": "moderator", "boolean": "or"}
            ]
        }
    ]
}
```

| Параметр | Тип | Описание |
|----------|-----|----------|
| `type` | string | `single` (по умолчанию) или `group` |
| `column` | string | Столбец для фильтрации |
| `operator` | string | Оператор: `=`, `!=`, `>`, `<`, `>=`, `<=`, `like`, `not like`, `in`, `not in` |
| `value` | mixed | Значение для сравнения. Может быть массивом для `in`/`not in`. |
| `value_type` | string | `scalar` (значение) или `pointer` (ссылка на другой столбец) |
| `boolean` | string | `and` (по умолчанию) или `or` |
| `group` | array | Вложенные условия (для `type: group`) |

### Order (Сортировка)

```json
{
    "order": [
        {"column": "created_at", "direction": "desc"},
        {"column": "name", "direction": "asc", "null_position": "last"}
    ]
}
```

| Параметр | Тип | Описание |
|----------|-----|----------|
| `column` | string | Столбец для сортировки |
| `direction` | string | `asc` или `desc` |
| `null_position` | string | Позиция NULL значений: `first` или `last` |

### Join (Присоединение таблиц)

Используется для фильтрации/сортировки по связанным таблицам или аналитических запросов.

```json
{
    "join": [
        {
            "type": "left",
            "table": "categories",
            "on": {
                "left": "articles.category_id",
                "operator": "=",
                "right": "categories.id"
            },
            "where": [
                {"column": "categories.active", "operator": "=", "value": true}
            ]
        }
    ]
}
```

| Параметр | Тип | Описание |
|----------|-----|----------|
| `type` | string | Тип JOIN: `left`, `right`, `inner`, `full` |
| `table` | string | Имя таблицы для присоединения |
| `on` | object | Условие ON с `left`, `operator`, `right` |
| `where` | array? | Дополнительные условия WHERE внутри JOIN |

**Примечание:** Для получения связанных данных в обычных запросах лучше использовать `with` (relationships).

### Group By (Группировка)

Используется вместе с агрегациями в `select`.

```json
{
    "select": [
        {"column": "status"},
        {"column": "id", "aggregation": "count", "alias": "total"}
    ],
    "group_by": ["status"]
}
```

### With (Relationships)

Загрузка связанных сущностей через Eloquent relationships.

```json
{
    "with": {
        "relationships": ["author", "comments"],
        "properties": ["full_name"]
    }
}
```

### Пагинация

```json
{
    "limit": 10,
    "page": 1,
    "paginate": true
}
```

Для аналитических запросов (с агрегациями или group_by) используется простой LIMIT/OFFSET вместо Laravel paginate().

### Аналитические запросы

При наличии агрегаций или group_by запрос считается аналитическим:
- Ответ возвращается как сырые данные, а не через ресурс
- Relationships не загружаются
- Пагинация работает через LIMIT/OFFSET

**Пример аналитического запроса:**

```json
{
    "select": [
        {"column": "status"},
        {"column": "category_id"},
        {"column": "id", "aggregation": "count", "alias": "total"},
        {"column": "views", "aggregation": "sum", "alias": "total_views"}
    ],
    "join": [
        {
            "type": "left",
            "table": "categories",
            "on": {"left": "articles.category_id", "operator": "=", "right": "categories.id"}
        }
    ],
    "where": [
        {"column": "created_at", "operator": ">=", "value": "2024-01-01"}
    ],
    "group_by": ["status", "category_id"],
    "order": [
        {"column": "total", "direction": "desc"}
    ]
}
```

**Ответ:**

```json
{
    "data": [
        {"status": "published", "category_id": 1, "total": 150, "total_views": 45000},
        {"status": "published", "category_id": 2, "total": 89, "total_views": 23000},
        {"status": "draft", "category_id": 1, "total": 23, "total_views": 0}
    ]
}
```

### Фильтрация полей в ресурсах

При указании конкретных полей в `select` (без агрегаций), ресурс вернёт только запрошенные поля:

```json
{
    "select": [
        {"column": "id"},
        {"column": "name"},
        {"column": "email"}
    ]
}
```

Для работы этой функции в вашем ресурсе используйте метод `filterByRequestedFields()`:

```php
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at,
        ];

        return $this->filterByRequestedFields($data);
    }
}
```

---

## Лицензия

Лицензия MIT. Для получения большей информации обращайтесь к [тексту лицензии](LICENSE.md).
