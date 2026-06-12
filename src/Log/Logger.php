<?php

declare(strict_types=1);

/**
 * Логгер плагина.
 *
 * Обеспечивает структурированное логирование с записью в:
 * - стандартный error_log WordPress (всегда)
 * - таблицу ng_auth_verification_log (только при http_logging=1)
 *
 * Безопасность данных:
 * - При http_logging=0 — OTP-коды маскируются в error_log (заменяются на «***»).
 * - При http_logging=1 — OTP-коды пишутся как есть (для отладки).
 *
 * Уровни логирования: DEBUG, INFO, WARNING, ERROR.
 *
 * @package NG_Auth
 */

class NG_Auth_Log_Logger
{
    private const PREFIX = '[NG Auth]';

    /**
     * Отладочное сообщение.
     *
     * Записывается только при WP_DEBUG=true.
     *
     * @param string $message Текст сообщения.
     * @param array  $context Дополнительные данные.
     */
    public static function debug(string $message, array $context = []): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        self::write('DEBUG', $message, $context);
    }

    /**
     * Информационное сообщение.
     *
     * Всегда записывается в error_log. В БД — только при http_logging=1.
     *
     * @param string $message Текст сообщения.
     * @param array  $context Дополнительные данные.
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * Предупреждение.
     *
     * Всегда записывается в error_log. В БД — только при http_logging=1.
     *
     * @param string $message Текст сообщения.
     * @param array  $context Дополнительные данные.
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    /**
     * Ошибка.
     *
     * Всегда записывается в error_log. В БД — только при http_logging=1.
     *
     * @param string $message Текст сообщения.
     * @param array  $context Дополнительные данные.
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * Формирование и запись лог-записи.
     *
     * @param string $level   Уровень логирования (DEBUG, INFO, WARNING, ERROR).
     * @param string $message Текст сообщения.
     * @param array  $context Дополнительные данные.
     */
    private static function write(string $level, string $message, array $context): void
    {
        $http_logging = self::is_http_logging_enabled();

        // Всегда пишем в стандартный лог WordPress (wp-content/debug.log при WP_DEBUG).
        // При выключенном http_logging — маскируем OTP-коды в error_log.
        error_log(self::build_log_entry($level, $message, $context, $http_logging));

        // В БД пишем только при включённой галочке http_logging.
        if ($http_logging) {
            self::write_to_db($level, $message, $context);
        }
    }

    /**
     * Сборка строки для error_log.
     *
     * При http_logging=0 — маскирует OTP-коды в message и context.
     *
     * @param string $level        Уровень логирования.
     * @param string $message      Текст сообщения.
     * @param array  $context      Дополнительные данные.
     * @param bool   $http_logging Флаг детального логирования.
     * @return string Готовая строка для error_log.
     */
    private static function build_log_entry(string $level, string $message, array $context, bool $http_logging): string
    {
        if (!$http_logging) {
            $message = self::mask_otp($message);
            $context = self::mask_otp_in_context($context);
        }

        $entry = self::PREFIX . ' [' . $level . '] ' . $message;

        if (!empty($context)) {
            $entry .= ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        return $entry;
    }

    /**
     * Запись в таблицу журнала верификации (для просмотра в админке).
     *
     * Вызывается только при http_logging=1. OTP не маскируются.
     *
     * @param string $level   Уровень логирования.
     * @param string $message Текст сообщения.
     * @param array  $context Дополнительные данные.
     */
    private static function write_to_db(string $level, string $message, array $context): void
    {
        global $wpdb;

        if (!$wpdb) {
            return;
        }

        $table = $wpdb->prefix . NG_AUTH_LOG_TABLE;

        // Проверяем существование таблицы перед записью (избегаем фаталов на ранней активации).
        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($table_exists !== $table) {
            return;
        }

        $ip = '';
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        $db_message = '[' . $level . '] ' . $message;
        if (!empty($context)) {
            $db_message .= "\n" . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $wpdb->insert(
            $table,
            [
                'user_id' => get_current_user_id(),
                'provider' => 'system',
                'action' => 'http_log',
                'status' => 'ERROR' === $level ? 'failed' : 'pending',
                'ip_address' => $ip,
                'message' => $db_message,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Проверяет, включено ли детальное HTTP-логирование в настройках.
     *
     * @return bool
     */
    private static function is_http_logging_enabled(): bool
    {
        $settings = get_option('ng_auth_settings', []);
        return !empty($settings['http_logging']);
    }

    /**
     * Маскирует OTP-коды в строке.
     *
     * Заменяет числовые последовательности из 4 или 6 цифр,
     * похожие на OTP-коды, на «***».
     *
     * @param string $text Исходный текст.
     * @return string Текст с замаскированными OTP.
     */
    private static function mask_otp(string $text): string
    {
        // Маскируем стандартный паттерн: «Code: 123456» → «Code: ***».
        $text = preg_replace('/\b\d{4,6}\b/', '***', $text);

        return $text;
    }

    /**
     * Рекурсивно маскирует OTP-коды в значениях контекста.
     *
     * @param array $context Контекст логирования.
     * @return array Контекст с замаскированными OTP.
     */
    private static function mask_otp_in_context(array $context): array
    {
        // Чувствительные ключи, значения которых маскируются полностью.
        $sensitive_keys = ['code', 'otp', 'password', 'token', 'secret', 'api_key'];

        return self::recursive_mask($context, $sensitive_keys);
    }

    /**
     * Рекурсивный обход массива/строки с маскированием OTP и чувствительных полей.
     *
     * @param mixed  $data           Данные для маскирования.
     * @param array  $sensitive_keys Ключи, значения которых маскируются полностью.
     * @return mixed Данные с замаскированными значениями.
     */
    private static function recursive_mask($data, array $sensitive_keys)
    {
        if (is_string($data)) {
            return self::mask_otp($data);
        }

        if (!is_array($data)) {
            return $data;
        }

        $masked = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive_keys, true)) {
                $masked[$key] = '***';
            } elseif (is_array($value)) {
                $masked[$key] = self::recursive_mask($value, $sensitive_keys);
            } elseif (is_string($value)) {
                $masked[$key] = self::mask_otp($value);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }
}
