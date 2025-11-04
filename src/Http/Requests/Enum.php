<?php

declare(strict_types = 1);

namespace On1kel\HyperfLighty\Http\Requests;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\TranslatorInterface;
use Hyperf\Validation\Contract\Rule;
use TypeError;

final class Enum implements Rule
{
    public function __construct(
        public string $type,
        private ?string $customMessage = null,
    ) {
    }

    /**
     * Rule::passes — возвращает true/false без исключений и без $fail-колбэков.
     *
     * @param string $attribute
     * @param mixed  $value
     */
    public function passes($attribute, $value): bool
    {
        // null считается невалидным — как и в вашей исходной логике
        if ($value === null) {
            return false;
        }

        // если передали уже сам enum-объект нужного типа
        if (is_object($value) && $value instanceof $this->type) {
            return true;
        }

        // только для backed-enum: нужен tryFrom
        if (! function_exists('enum_exists') || ! enum_exists($this->type) || ! method_exists($this->type, 'tryFrom')) {
            return false;
        }

        try {
            // валиден, если tryFrom($value) вернул экземпляр, а не null
            return $this->type::tryFrom($value) !== null;
        } catch (TypeError) {
            return false;
        }
    }

    /**
     * Сообщение об ошибке. Возвращаем перевод, если доступен.
     */
    public function message(): string
    {
        if ($this->customMessage !== null) {
            return $this->customMessage;
        }

        $translator = $this->getTranslator();
        if ($translator) {
            return (string) $translator->trans('validation.enum');
        }

        // Фолбэк, если переводчик не подключён
        return 'The selected value is invalid.';
    }

    private function getTranslator(): ?TranslatorInterface
    {
        // Возвращаем переводчик, если пакет hyperf/translation установлен и бин зарегистрирован
        if (class_exists(ApplicationContext::class)) {
            $container = ApplicationContext::getContainer();
            if ($container->has(TranslatorInterface::class)) {
                /** @var TranslatorInterface $translator */
                $translator = $container->get(TranslatorInterface::class);

                return $translator;
            }
        }

        return null;
    }
}
