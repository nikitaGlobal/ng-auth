<?php

declare(strict_types=1);

/**
 * Сервис локаута — временная блокировка email/телефона после превышения попыток OTP.
 *
 * После 4 неверных попыток:
 * - email пользователя блокируется на заданное время
 * - номер телефона блокируется на заданное время (независимо)
 * - блокировки хранятся в wp_options как transient'ы
 *
 * @package NG_Auth
 */

class NG_Auth_Core_Lockout_Service
{
    /**
     * Префикс для transient-ключей.
     */
    private const PREFIX = 'ng_auth_lockout_';

    /**
     * Значение по умолчанию — 1 час.
     */
    private const DEFAULT_DURATION = 3600;

    /**
     * Блокировка email.
     *
     * @param string $email Email для блокировки.
     * @return void
     */
    public function lock_email(string $email): void
    {
        set_transient($this->key('email', $email), time(), $this->get_duration());
    }

    /**
     * Блокировка телефона.
     *
     * @param string $phone Номер телефона (нормализованный, без +).
     * @return void
     */
    public function lock_phone(string $phone): void
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        set_transient($this->key('phone', $phone), time(), $this->get_duration());
    }

    /**
     * Проверка, заблокирован ли email.
     *
     * @param string $email Email для проверки.
     * @return bool
     */
    public function is_email_locked(string $email): bool
    {
        return false !== get_transient($this->key('email', $email));
    }

    /**
     * Проверка, заблокирован ли телефон.
     *
     * @param string $phone Номер телефона.
     * @return bool
     */
    public function is_phone_locked(string $phone): bool
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        return false !== get_transient($this->key('phone', $phone));
    }

    /**
     * Оставшееся время блокировки email в секундах.
     *
     * @param string $email Email.
     * @return int 0 если не заблокирован.
     */
    public function email_lock_remaining(string $email): int
    {
        $locked_at = get_transient($this->key('email', $email));
        if (false === $locked_at) {
            return 0;
        }
        $remaining = (int) $locked_at + $this->get_duration() - time();
        return max(0, $remaining);
    }

    /**
     * Оставшееся время блокировки телефона в секундах.
     *
     * @param string $phone Номер телефона.
     * @return int 0 если не заблокирован.
     */
    public function phone_lock_remaining(string $phone): int
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $locked_at = get_transient($this->key('phone', $phone));
        if (false === $locked_at) {
            return 0;
        }
        $remaining = (int) $locked_at + $this->get_duration() - time();
        return max(0, $remaining);
    }

    /**
     * Сброс блокировки email.
     *
     * @param string $email Email.
     * @return void
     */
    public function unlock_email(string $email): void
    {
        delete_transient($this->key('email', $email));
    }

    /**
     * Сброс блокировки телефона.
     *
     * @param string $phone Номер телефона.
     * @return void
     */
    public function unlock_phone(string $phone): void
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        delete_transient($this->key('phone', $phone));
    }

    /**
     * Длительность блокировки из настроек.
     *
     * @return int Секунды.
     */
    private function get_duration(): int
    {
        $config = NG_Auth_Config::instance();
        return $config->get_int('lockout_duration', self::DEFAULT_DURATION);
    }

    /**
     * Ключ transient'а.
     */
    private function key(string $type, string $value): string
    {
        return self::PREFIX . $type . '_' . md5($value);
    }
}
