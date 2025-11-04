<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use On1kel\HyperfLighty\Services\Encrypter;
use SodiumException;

class EncryptCast implements CastsAttributes
{
    protected Encrypter $encrypter;

    public function __construct()
    {
        $this->encrypter = Encrypter::getInstance();
    }

    /**
     * Cast the given value.
     *
     * @param  Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string>  $attributes
     *
     * @return mixed
     * @throws SodiumException
     * @throws JsonException
     */
    public function get($model, string $key, $value, array $attributes): mixed
    {
        return $this->encrypter->decrypt($value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string>  $attributes
     * @return string|null
     *
     * @throws SodiumException
     */
    public function set($model, string $key, $value, array $attributes): ?string
    {
        return $this->encrypter->encrypt($value);
    }
}
