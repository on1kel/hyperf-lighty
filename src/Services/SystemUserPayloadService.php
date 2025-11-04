<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

class SystemUserPayloadService
{
    /** @var array{string:string,int:int} */
    private static array $systemUserId = [
        'string' => '24bc67bb-6c4f-4f8e-884e-4f25f7857b03',
        'int' => 1,
    ];

    private static string $systemPassword = 'Ysj7hYgZgi';

    /**
     * Возвращает системный ID в зависимости от типа первичного ключа модели пользователя.
     * Путь до класса берётся из конфига: auth.providers.users.model
     */
    public static function getSystemUserId(): int|string
    {
        $container = ApplicationContext::getContainer();
        /** @var ConfigInterface $config */
        $config = $container->get(ConfigInterface::class);

        /** @var mixed $userClass */
        $userClass = $config->get('auth.providers.users.model');

        if (! is_string($userClass) || ! class_exists($userClass)) {
            // запасной вариант — числовой ID
            return self::$systemUserId['int'];
        }

        $user = new $userClass();

        $keyType = method_exists($user, 'getKeyType')
            ? (string) $user->getKeyType()
            : 'int';

        return $keyType === 'string'
            ? self::$systemUserId['string']
            : self::$systemUserId['int'];
    }

    /**
     * Базовый payload «системного пользователя».
     *
     * @return array{id:int|string,name:string,email:string,password:string}
     */
    public static function getSystemUserPayload(): array
    {
        return [
            'id' => self::getSystemUserId(),
            'name' => 'System',
            'email' => 'system@site.ru',
            'password' => self::$systemPassword,
        ];
    }
}
