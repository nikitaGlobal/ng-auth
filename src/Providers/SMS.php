<?php

/**
 * Абстрактный класс SMS-провайдера верификации.
 *
 * Реализует общую логику отправки OTP через SMS и проверки кода.
 * Конкретные SMS-провайдеры должны реализовать только метод send_sms() —
 * фактическую отправку сообщения через API выбранного сервиса.
 *
 * Дочерний класс обязан реализовать:
 * - send_sms(string $phone, string $message): bool
 *
 * @package NG_Auth
 */

declare(strict_types=1);

abstract class NG_Auth_Providers_SMS extends NG_Auth_Providers_Base
{
    /**
     * Отправка SMS-сообщения через API провайдера.
     *
     * Должна быть реализована в конкретном SMS-провайдере.
     *
     * @param string $phone   Номер телефона получателя.
     * @param string $message Текст сообщения.
     * @return bool true в случае успешной отправки.
     */
    abstract protected function send_sms(string $phone, string $message): bool;

    /**
     * Инициация верификации: генерация и отправка OTP-кода.
     *
     * Проверяет наличие номера телефона, интервал повторной отправки,
     * генерирует OTP и отправляет его через send_sms().
     *
     * Логирует каждый этап для диагностики (через NG_Auth_Log_Logger).
     *
     * @param WP_User $user Пользователь.
     * @return NG_Auth_Verification_Result Результат инициации.
     */
    public function initiate_verification(WP_User $user): NG_Auth_Verification_Result
    {
        $phone = $this->get_user_phone($user);
        if ('' === $phone) {
            NG_Auth_Log_Logger::warning('SMS initiation failed: no phone', [
                'provider' => $this->get_id(),
                'user_id' => $user->ID,
            ]);
            return NG_Auth_Verification_Result::failure(
                __('Номер телефона не указан.', 'ng-auth')
            );
        }

        $storage = $this->get_storage();
        if (!$storage->can_resend_otp($user)) {
            $remaining = $storage->get_otp_ttl($user);
            NG_Auth_Log_Logger::info('SMS initiation blocked: resend interval', [
                'provider' => $this->get_id(),
                'user_id' => $user->ID,
                'otp_ttl_remaining' => $remaining,
            ]);
            return NG_Auth_Verification_Result::failure(
                __('Подождите перед повторной отправкой кода.', 'ng-auth')
            );
        }

        $otp = $storage->generate_otp($user);
        $storage->set_phone($user, $phone);

        if ($this->is_test_mode()) {
            NG_Auth_Log_Logger::info('SMS test mode: OTP generated (not sent via API)', [
                'provider' => $this->get_id(),
                'user_id' => $user->ID,
                'code' => $otp,
            ]);
            $storage->log_verification(
                $user,
                $this->get_id(),
                'test_otp',
                'pending',
                sprintf(__('Тестовый код: %s', 'ng-auth'), $otp)
            );
        }

        $settings = get_option('ng_auth_settings', []);
        $template = $settings['sms_otp_template'] ?? __('Ваш код подтверждения: {code}', 'ng-auth');
        $message = str_replace('{code}', $otp, $template);

        NG_Auth_Log_Logger::info('SMS sending OTP', [
            'provider' => $this->get_id(),
            'user_id' => $user->ID,
            'phone' => $phone,
            'test_mode' => $this->is_test_mode(),
        ]);

        $sent = $this->send_sms($phone, $message);

        if (!$sent) {
            $storage->clear_otp($user);
            NG_Auth_Log_Logger::error('SMS send failed', [
                'provider' => $this->get_id(),
                'user_id' => $user->ID,
                'phone' => $phone,
            ]);
            return NG_Auth_Verification_Result::failure(
                __('Не удалось отправить SMS. Попробуйте позже.', 'ng-auth')
            );
        }

        $storage->mark_pending($user, $this->get_id());

        NG_Auth_Log_Logger::info('SMS OTP sent successfully', [
            'provider' => $this->get_id(),
            'user_id' => $user->ID,
        ]);

        return NG_Auth_Verification_Result::success(
            __('Код подтверждения отправлен на ваш номер телефона.', 'ng-auth')
        );
    }

    /**
     * Проверяет, включён ли режим тестирования.
     *
     * @return bool
     */
    public function is_test_mode(): bool
    {
        return (bool) $this->get_option('test_mode', false);
    }

    /**
     * Проверка OTP-кода, введённого пользователем.
     *
     * Проверяет код, учитывает лимит попыток и блокировку.
     * При успехе — отмечает пользователя как подтверждённого.
     *
     * @param WP_User              $user Пользователь.
     * @param array<string, mixed> $data Данные формы (ключ 'code').
     * @return NG_Auth_Verification_Result Результат проверки.
     */
    public function verify(WP_User $user, array $data): NG_Auth_Verification_Result
    {
        $code = sanitize_text_field($data['code'] ?? '');

        if ('' === $code) {
            return NG_Auth_Verification_Result::failure(
                __('Введите код подтверждения.', 'ng-auth')
            );
        }

        $storage = $this->get_storage();
        $lockout = new NG_Auth_Core_Lockout_Service();

        if (NG_AUTH_STATUS_BLOCKED === $storage->get_status($user)) {
            return NG_Auth_Verification_Result::failure(
                __('Ваша учётная запись заблокирована.', 'ng-auth')
            );
        }

        if ($storage->verify_otp($user, $code)) {
            $storage->mark_verified($user, $this->get_id());
            $storage->log_verification($user, $this->get_id(), 'verify', 'verified');

            return NG_Auth_Verification_Result::success(
                __('Подтверждение успешно пройдено.', 'ng-auth')
            );
        }

        $attempts = $storage->get_otp_attempts($user);
        $config = NG_Auth_Config::instance();
        $max_attempts = $config->get_int('lockout_max_attempts', 4);

        if ($max_attempts <= $attempts) {
            // Блокируем email и телефон независимо.
            $lockout->lock_email($user->user_email);
            $phone_raw = get_user_meta($user->ID, 'ng_auth_phone_raw', true);
            if ('' !== $phone_raw) {
                $lockout->lock_phone($phone_raw);
            }

            $storage->log_verification($user, $this->get_id(), 'block', 'blocked');
            $storage->clear_otp($user);

            NG_Auth_Log_Logger::warning('User locked out after failed OTP attempts', [
                'user_id' => $user->ID,
                'email' => $user->user_email,
            ]);

            return NG_Auth_Verification_Result::failure(
                __('Превышено число попыток. Email и телефон заблокированы на 1 час.', 'ng-auth'),
                ['locked_out' => true]
            );
        }

        $storage->log_verification($user, $this->get_id(), 'fail', 'failed');

        return NG_Auth_Verification_Result::failure(
            sprintf(
                __('Неверный код. Осталось попыток: %d.', 'ng-auth'),
                $max_attempts - $attempts
            )
        );
    }

    /**
     * Возвращает поля настроек для SMS-провайдера.
     *
     * Включает стандартные поля: enabled, api_key, api_secret, sender_name, priority.
     *
     * @return array<string, array{label: string, type: string, default: mixed}> Поля настроек.
     */
    public function init_form_fields(): array
    {
        $prefix = 'provider_' . $this->get_id();
        $settings = get_option('ng_auth_settings', []);

        return [
            "{$prefix}_enabled" => [
                'label' => sprintf(__('Включить провайдера %s', 'ng-auth'), $this->get_name()),
                'type' => 'checkbox',
                'default' => false,
            ],
            "{$prefix}_api_key" => [
                'label' => __('API Key', 'ng-auth'),
                'type' => 'text',
                'default' => $settings["{$prefix}_api_key"] ?? '',
            ],
            "{$prefix}_api_secret" => [
                'label' => __('API Secret', 'ng-auth'),
                'type' => 'password',
                'default' => $settings["{$prefix}_api_secret"] ?? '',
            ],
            "{$prefix}_sender_name" => [
                'label' => __('Имя отправителя', 'ng-auth'),
                'type' => 'text',
                'default' => $settings["{$prefix}_sender_name"] ?? 'NG.Auth',
            ],
            "{$prefix}_priority" => [
                'label' => __('Приоритет', 'ng-auth'),
                'type' => 'number',
                'default' => $settings["{$prefix}_priority"] ?? 10,
            ],
        ];
    }

    /**
     * Получение номера телефона пользователя.
     *
     * Проверяет мета-поля billing_phone, phone и POST-параметр ng_auth_phone.
     *
     * @param WP_User $user Пользователь.
     * @return string Номер телефона или пустая строка.
     */
    protected function get_user_phone(WP_User $user): string
    {
        $phone = get_user_meta($user->ID, 'billing_phone', true);
        if ('' === $phone) {
            $phone = get_user_meta($user->ID, 'phone', true);
        }
        if ('' === $phone && isset($_POST['ng_auth_phone'])) {
            $phone = sanitize_text_field(wp_unslash($_POST['ng_auth_phone']));
        }
        return (string) $phone;
    }

    /**
     * Получение экземпляра хранилища мета-данных.
     *
     * @return NG_Auth_Storage_User_Meta_Storage
     */
    protected function get_storage(): NG_Auth_Storage_User_Meta_Storage
    {
        return new NG_Auth_Storage_User_Meta_Storage();
    }
}
