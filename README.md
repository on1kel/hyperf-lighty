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

use App\Process\Attributes\Roles;
use Hyperf\Di\ReflectionManager;

$rolesEnv = env('APP_ROLES', 'api');
$enabledRoles = array_values(array_filter(array_map('trim', explode(',', $rolesEnv))));
$enabledRoles = $enabledRoles ?: ['api'];

$registry = require __DIR__ . '/process_registry.php';

$enabled = [];
foreach ($registry as $class) {
    $ref = ReflectionManager::reflectClass($class);
    $attrs = $ref->getAttributes(Roles::class);

    // Процессы без ролей считаются универсальными
    if ($attrs === []) {
        $enabled[] = $class;
        continue;
    }

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

## Безопасность

- Используется расширение `ext-sodium` для безопасного шифрования (`sodium_crypto_secretbox`).
- Все ключи и токены рекомендуется хранить в `.env`.
- Поддержка строгой типизации (`declare(strict_types=1)`).


## Лицензия
 
См. файл [LICENSE](LICENSE) для подробностей.
