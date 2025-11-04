<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Requests;

use Hyperf\Validation\Request\FormRequest;

/**
 * Базовый FormRequest для Hyperf.
 * Реализует те же точки расширения, что и в Laravel-подходе:
 *  - rules(): array<string, mixed>
 *  - messages(): array<string, string>
 *  - attributes(): array<string, string>
 * Плюс хук prepareForValidation(array $input): array — предобработка входных данных.
 */
abstract class BaseRequest extends FormRequest
{
    /**
     * Разрешить выполнение запроса (аналог Laravel authorize()).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Правила валидации.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Локализованные имена атрибутов (для сообщений об ошибках).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            // 'email' => 'email address',
        ];
    }

    /**
     * Кастомные сообщения об ошибках.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // 'title.required' => 'A title is required',
            // 'body.required'  => 'A message is required',
        ];
    }

    /**
     * Твой хук предобработки входных данных.
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function prepareInput(array $input): array
    {
        return $input;
    }

    /**
     * Переопределяем данные, которые уйдут в валидатор (аналог Laravel prepareForValidation()).
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        // parent::validationData() обычно возвращает $this->all()
        $input = parent::validationData();

        // Дадим возможность наследнику преобразовать входные данные.
        $prepared = $this->prepareInput($input);

        // Гарантируем, что вернём массив.
        return is_array($prepared) ? $prepared : $input;
    }
}
