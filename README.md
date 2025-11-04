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
> "scripts": {
>     "post-install-cmd": [
>         "@php bin/hyperf.php ide-helper:model"
>     ]
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
---

## Безопасность

- Используется расширение `ext-sodium` для безопасного шифрования (`sodium_crypto_secretbox`).
- Все ключи и токены рекомендуется хранить в `.env`.
- Поддержка строгой типизации (`declare(strict_types=1)`).


## Лицензия
 
См. файл [LICENSE](LICENSE) для подробностей.
