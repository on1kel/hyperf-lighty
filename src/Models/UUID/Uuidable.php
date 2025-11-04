<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Models\UUID;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use On1kel\HyperfLighty\Exceptions\Models\ModelUUIDVersionUnsupportedException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Ramsey\Uuid\Uuid;

trait Uuidable
{
    /**
     * @return string
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function generateUuid(): string
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $uuidVersion = $config->get('lighty.models.uuid.version', 4);

        return match ($uuidVersion) {
            1 => Uuid::uuid1()->toString(),
            4 => Uuid::uuid4()->toString(),
            6 => Uuid::uuid6()->toString(),
            default => throw new ModelUUIDVersionUnsupportedException($uuidVersion),
        };
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     *
     * @return bool
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * Get the auto-incrementing key type.
     *
     * @return string
     */
    public function getKeyType(): string
    {
        return 'string';
    }
}
