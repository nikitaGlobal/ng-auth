<?php

declare(strict_types=1);

/**
 * Конфигурационный слой плагина NG Auth.
 *
 * Единая точка чтения и записи всех настроек плагина.
 * Все настройки хранятся в wp_options с ключом 'ng_auth_settings'.
 *
 * Префиксы ключей:
 * - provider_{id}_* — настройки конкретного провайдера.
 * - Остальные ключи — глобальные настройки плагина.
 *
 * @package NG_Auth
 */

class NG_Auth_Config
{
    /**
     * Имя опции в wp_options.
     */
    private const OPTION_KEY = 'ng_auth_settings';

    /**
     * Значения по умолчанию для всех настроек.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        // Общие настройки
        'enable_mandatory' => false,
        'selected_roles' => [],
        'require_russian_ip' => false,
        'new_user_mode' => 'soft', // soft | hard

        // OTP-настройки
        'otp_length' => 6,
        'otp_ttl' => 300,
        'otp_max_attempts' => 4,
        'otp_resend_interval' => 60,

        // Локаут после превышения попыток
        'lockout_max_attempts' => 4,
        'lockout_duration' => 3600, // 1 час

        // Шаблоны сообщений
        'sms_otp_template' => 'Ваш код подтверждения: {code}',
        'sms_reminder_template' => 'Подтвердите ваш аккаунт на сайте {site_name}',
        'email_reminder_subject' => 'Подтверждение аккаунта на {site_name}',
        'email_reminder_body' => '',
        'registration_notice' => '',

        // Напоминания
        'reminder_enabled' => false,
        'reminder_delay_days' => 3,
        'reminder_batch_size' => 50,

        // Гео-провайдер
        'geo_provider' => '',

        // Логирование
        'http_logging' => false,
    ];

    /**
     * Экземпляр конфига (Singleton).
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Загруженные настройки (кэш).
     *
     * @var array<string, mixed>|null
     */
    private ?array $settings = null;

    /**
     * Приватный конструктор.
     */
    private function __construct() {}

    /**
     * Получение экземпляра конфига.
     *
     * @return self
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ──────────────────────────────────────────────
    // Чтение
    // ──────────────────────────────────────────────

    /**
     * Получение значения настройки.
     *
     * @param string $key     Ключ настройки.
     * @param mixed  $default Значение по умолчанию (если не задано в DEFAULTS и не сохранено).
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $settings = $this->load();

        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        if (null !== $default) {
            return $default;
        }

        return self::DEFAULTS[$key] ?? null;
    }

    /**
     * Получение значения настройки как строки.
     *
     * @param string $key     Ключ настройки.
     * @param string $default Значение по умолчанию.
     * @return string
     */
    public function get_string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    /**
     * Получение значения настройки как целого числа.
     *
     * @param string $key     Ключ настройки.
     * @param int    $default Значение по умолчанию.
     * @return int
     */
    public function get_int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    /**
     * Получение значения настройки как булева.
     *
     * @param string $key     Ключ настройки.
     * @param bool   $default Значение по умолчанию.
     * @return bool
     */
    public function get_bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    /**
     * Получение значения настройки как массива.
     *
     * @param string $key     Ключ настройки.
     * @param array  $default Значение по умолчанию.
     * @return array
     */
    public function get_array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);
        return is_array($value) ? $value : $default;
    }

    /**
     * Получение настройки провайдера.
     *
     * @param string $provider_id Идентификатор провайдера.
     * @param string $key         Ключ настройки (без префикса provider_{id}_).
     * @param mixed  $default     Значение по умолчанию.
     * @return mixed
     */
    public function get_provider(string $provider_id, string $key, $default = null)
    {
        return $this->get('provider_' . $provider_id . '_' . $key, $default);
    }

    /**
     * Получение булевой настройки провайдера.
     *
     * @param string $provider_id Идентификатор провайдера.
     * @param string $key         Ключ настройки.
     * @param bool   $default     Значение по умолчанию.
     * @return bool
     */
    public function get_provider_bool(string $provider_id, string $key, bool $default = false): bool
    {
        return (bool) $this->get_provider($provider_id, $key, $default);
    }

    /**
     * Получение всех настроек разом.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->load();
    }

    // ──────────────────────────────────────────────
    // Запись
    // ──────────────────────────────────────────────

    /**
     * Установка значения настройки.
     *
     * @param string $key   Ключ настройки.
     * @param mixed  $value Значение.
     * @return void
     */
    public function set(string $key, $value): void
    {
        $settings = $this->load();
        $settings[$key] = $value;
        $this->save($settings);
    }

    /**
     * Массовое обновление настроек.
     *
     * @param array<string, mixed> $values Ассоциативный массив ключ => значение.
     * @return void
     */
    public function set_many(array $values): void
    {
        $settings = $this->load();

        foreach ($values as $key => $value) {
            $settings[$key] = $value;
        }

        $this->save($settings);
    }

    /**
     * Установка настройки провайдера.
     *
     * @param string $provider_id Идентификатор провайдера.
     * @param string $key         Ключ настройки.
     * @param mixed  $value       Значение.
     * @return void
     */
    public function set_provider(string $provider_id, string $key, $value): void
    {
        $this->set('provider_' . $provider_id . '_' . $key, $value);
    }

    /**
     * Удаление настройки.
     *
     * @param string $key Ключ настройки.
     * @return void
     */
    public function delete(string $key): void
    {
        $settings = $this->load();
        unset($settings[$key]);
        $this->save($settings);
    }

    /**
     * Сброс всех настроек к значениям по умолчанию.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->save(self::DEFAULTS);
    }

    // ──────────────────────────────────────────────
    // Внутренние методы
    // ──────────────────────────────────────────────

    /**
     * Загрузка настроек из базы данных с кэшированием.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if (null === $this->settings) {
            $saved = get_option(self::OPTION_KEY, []);

            if (!is_array($saved)) {
                $saved = [];
            }

            // Мержим defaults + сохранённые (сохранённые имеют приоритет).
            $this->settings = array_merge(self::DEFAULTS, $saved);
        }

        return $this->settings;
    }

    /**
     * Сохранение настроек в базу данных и обновление кэша.
     *
     * Пишет только те ключи, которые отличаются от DEFAULTS,
     * чтобы не затирать provider-ключи, отсутствующие в DEFAULTS.
     *
     * @param array<string, mixed> $values Полный массив настроек.
     * @return void
     */
    private function save(array $values): void
    {
        // Пишем только те ключи, что отличаются от defaults или отсутствуют в defaults.
        $to_save = [];
        foreach ($values as $key => $value) {
            // Ключи, отсутствующие в defaults — всегда сохраняем (provider-ключи и т.д.).
            if (!array_key_exists($key, self::DEFAULTS)) {
                $to_save[$key] = $value;
                continue;
            }
            // Ключи из defaults — сохраняем только если значение отличается.
            if ($value !== self::DEFAULTS[$key]) {
                $to_save[$key] = $value;
            }
        }

        update_option(self::OPTION_KEY, $to_save);
        $this->settings = $values;
    }

    /**
     * Сброс кэша (для случаев, когда настройки меняются в обход Config).
     *
     * @return void
     */
    public function flush_cache(): void
    {
        $this->settings = null;
    }
}
