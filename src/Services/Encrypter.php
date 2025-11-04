<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Services;

use function base64_decode;
use function base64_encode;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

use function is_array;
use function is_null;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Khazhinov\PhpSupport\Patterns\Singleton;

use function mb_strlen;
use function random_bytes;

use RuntimeException;

use function serialize;
use function sodium_crypto_auth;
use function sodium_crypto_auth_verify;
use function sodium_crypto_secretbox;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

use function sodium_crypto_secretbox_keygen;

use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

use function sodium_crypto_secretbox_open;

use SodiumException;

use function substr;

use Throwable;

use function unserialize;

/**
 * @method static Encrypter getInstance()
 */
class Encrypter extends Singleton
{
    /**
     * Ключ шифрования (SODIUM_CRYPTO_SECRETBOX_KEYBYTES = 32 байта).
     */
    protected string $key;

    /**
     * Инициализация — берём ключ из конфига app.key.
     * Поддерживается формат 'base64:...' (как в Laravel), но без зависимостей.
     */
    protected function init(): void
    {
        /** @var ConfigInterface $config */
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);

        $key = (string) $config->get('app.key', '');

        // поддержка префикса 'base64:' без Illuminate\Support\Str
        if (substr($key, 0, 7) === 'base64:') {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('Invalid base64 app.key.');
            }
            $key = $decoded;
        }

        if (! self::supported($key)) {
            throw new RuntimeException('Incorrect key provided (length mismatch).');
        }

        $this->key = $key;
    }

    /**
     * Валидность ключа для secretbox.
     */
    public static function supported(string $key): bool
    {
        return mb_strlen($key, '8bit') === SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    }

    /**
     * Генерация нового ключа secretbox.
     */
    public function generateKey(): string
    {
        return sodium_crypto_secretbox_keygen();
    }

    /**
     * Шифрование значения (по умолчанию с сериализацией).
     *
     * Возвращает base64(JSON{nonce,value,mac}), где поля закодированы base64.
     *
     * @throws SodiumException
     * @throws JsonException
     */
    public function encrypt(mixed $value, bool $serialize = true): ?string
    {
        $this->init();

        if (is_null($value)) {
            return null;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plaintext = $serialize ? serialize($value) : (string) $value;
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        } catch (Throwable $e) {
            throw new RuntimeException('Encryption failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $mac = sodium_crypto_auth($cipher, $this->key);

        $json = json_encode(
            array_map('base64_encode', [
                'nonce' => $nonce,
                'value' => $cipher,
                'mac' => $mac,
            ]),
            JSON_THROW_ON_ERROR
        );

        return base64_encode($json);
    }

    /**
     * Шифрование строки без сериализации.
     *
     * @throws SodiumException
     * @throws JsonException
     */
    public function encryptString(string $value): ?string
    {
        return $this->encrypt($value, false);
    }

    /**
     * Расшифровка значения (по умолчанию — с десериализацией).
     *
     * @throws SodiumException
     * @throws JsonException
     */
    public function decrypt(mixed $payload, bool $serialize = true): mixed
    {
        $this->init();

        if (is_null($payload)) {
            return null;
        }

        $p = $this->getJsonPayload((string) $payload);

        $decrypted = sodium_crypto_secretbox_open($p['value'], $p['nonce'], $this->key);
        if ($decrypted === false) {
            throw new RuntimeException('Could not decrypt the data.');
        }

        return $serialize
            ? unserialize($decrypted, ['allowed_classes' => false])
            : $decrypted;
    }

    /**
     * Расшифровка строки без десериализации.
     *
     * @throws SodiumException
     * @throws JsonException
     */
    public function decryptString(string $payload): string
    {
        /** @var string $out */
        $out = $this->decrypt($payload, false);

        return $out;
    }

    /**
     * Извлечь и проверить JSON-пейлоад.
     *
     * @return array{nonce:string,value:string,mac:string}
     * @throws JsonException
     */
    protected function getJsonPayload(string $payload): array
    {
        $decodedOuter = base64_decode($payload, true);
        if ($decodedOuter === false) {
            throw new RuntimeException('Invalid base64 payload.');
        }

        /** @var array<string,string>|null $arr */
        $arr = json_decode($decodedOuter, true, 512, JSON_THROW_ON_ERROR);
        if (! $this->validPayload($arr)) {
            throw new RuntimeException('The payload is invalid.');
        }

        $nonce = base64_decode($arr['nonce'], true);
        $value = base64_decode($arr['value'], true);
        $mac = base64_decode($arr['mac'], true);

        if ($nonce === false || $value === false || $mac === false) {
            throw new RuntimeException('The payload is malformed (base64 parts).');
        }

        if (! $this->validMac(['mac' => $mac, 'value' => $value])) {
            throw new RuntimeException('The MAC is invalid.');
        }

        return ['nonce' => $nonce, 'value' => $value, 'mac' => $mac];
    }

    /**
     * Проверка формы полезной нагрузки.
     */
    protected function validPayload(mixed $payload): bool
    {
        return is_array($payload)
            && isset($payload['nonce'], $payload['value'], $payload['mac'])
            && is_string($payload['nonce'])
            && is_string($payload['value'])
            && is_string($payload['mac']);
    }

    /**
     * Проверка MAC.
     *
     * @param array{mac:string,value:string} $payload
     * @throws SodiumException
     */
    protected function validMac(array $payload): bool
    {
        return sodium_crypto_auth_verify($payload['mac'], $payload['value'], $this->key);
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
