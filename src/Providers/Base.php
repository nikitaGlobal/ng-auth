<?php

/**
 * Абстрактный базовый класс провайдера верификации.
 *
 * Предоставляет общую реализацию методов:
 * - get_option() — чтение настроек провайдера.
 * - is_available() — проверка активности провайдера.
 * - get_priority() — приоритет провайдера.
 * - get_logo_url(), get_provider_url(), get_instructions() — значения по умолчанию.
 *
 * Конкретные провайдеры должны реализовать:
 * - get_id()
 * - get_name()
 * - get_description()
 * - get_admin_fields()
 * - initiate_verification()
 * - verify()
 *
 * @package NG_Auth
 */

declare(strict_types=1);

abstract class NG_Auth_Providers_Base implements NG_Auth_Contracts_Provider
{
    /**
     * Получение значения конкретной настройки провайдера из общих настроек плагина.
     *
     * Ключ настройки формируется как `provider_{id}_{key}`.
     *
     * @param string $key     Ключ настройки.
     * @param mixed  $default Значение по умолчанию.
     * @return mixed Значение настройки.
     */
    protected function get_option(string $key, $default = '')
    {
        return NG_Auth_Config::instance()->get_provider($this->get_id(), $key, $default);
    }

    /**
     * Проверка доступности провайдера.
     *
     * {@inheritDoc}
     *
     * @return bool
     */
    public function is_available(): bool
    {
        return NG_Auth_Config::instance()->get_provider_bool($this->get_id(), 'enabled', false);
    }

    /**
     * Получение приоритета провайдера.
     *
     * Используется для сортировки провайдеров в интерфейсе выбора.
     *
     * @return int Приоритет (меньше = выше).
     */
    public function get_priority(): int
    {
        return NG_Auth_Config::instance()->get_provider($this->get_id(), 'priority', 10);
    }

    /**
     * URL логотипа провайдера (по умолчанию пустая строка).
     *
     * {@inheritDoc}
     *
     * @return string
     */
    public function get_logo_url(): string
    {
        return '';
    }

    /**
     * URL сайта провайдера (по умолчанию пустая строка).
     *
     * {@inheritDoc}
     *
     * @return string
     */
    public function get_provider_url(): string
    {
        return '';
    }

    /**
     * Инструкция по настройке провайдера (по умолчанию пустая строка).
     *
     * {@inheritDoc}
     *
     * @return string
     */
    public function get_instructions(): string
    {
        return '';
    }

    /**
     * Идентификатор провайдера.
     *
     * Должен быть реализован в дочернем классе.
     *
     * @return string
     */
    abstract public function get_id(): string;

    /**
     * Читаемое название провайдера.
     *
     * Должно быть реализовано в дочернем классе.
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * Описание провайдера.
     *
     * Должно быть реализовано в дочернем классе.
     *
     * @return string
     */
    abstract public function get_description(): string;

    /**
     * Поля настроек провайдера в админке.
     *
     * @deprecated Используйте init_form_fields().
     * @return array
     */
    public function get_admin_fields(): array
    {
        _deprecated_function(__METHOD__, '2.0.0', 'init_form_fields()');
        return $this->init_form_fields();
    }

    /**
     * Возвращает массив полей формы для страницы настроек провайдера.
     *
     * Должен быть реализован в дочернем классе.
     *
     * @return array<string, array{label: string, type: string, default: mixed}>
     */
    abstract public function init_form_fields(): array;

    /**
     * Сохраняет настройки провайдера из данных POST-запроса.
     *
     * По умолчанию проходит по всем полям из init_form_fields(),
     * читает их значения из $_POST и сохраняет в настройку ng_auth_settings.
     * Поля типа 'readonly' пропускаются.
     *
     * @return void
     */
    public function process_admin_options(): void
    {
        $config = NG_Auth_Config::instance();
        $fields = $this->init_form_fields();

        foreach ($fields as $key => $field) {
            $type = $field['type'] ?? 'text';
            if ('readonly' === $type) {
                continue;
            }

            if ('checkbox' === $type) {
                // Чекбоксы: если нет в POST — значит выключен, сохраняем '0'.
                // Так значение не пропадёт и Config::get_bool вернёт false.
                $config->set($key, isset($_POST[$key]) ? '1' : '0');
            } else {
                $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
                $config->set($key, $value);
            }
        }
    }

    /**
     * Инициация верификации.
     *
     * Должна быть реализована в дочернем классе.
     *
     * @param WP_User $user Пользователь.
     * @return NG_Auth_Verification_Result
     */
    abstract public function initiate_verification(WP_User $user): NG_Auth_Verification_Result;

    /**
     * Проверка данных верификации.
     *
     * Должна быть реализована в дочернем классе.
     *
     * @param WP_User              $user Пользователь.
     * @param array<string, mixed> $data Данные для проверки.
     * @return NG_Auth_Verification_Result
     */
    abstract public function verify(WP_User $user, array $data): NG_Auth_Verification_Result;
}
