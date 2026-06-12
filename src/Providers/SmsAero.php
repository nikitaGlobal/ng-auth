<?php

declare(strict_types=1);

/**
 * Провайдер верификации SMS Aero.
 *
 * Интеграция с SMS Aero API v2 (https://gate.smsaero.ru/v2).
 * Аутентификация: HTTP Basic Auth (email:api_key).
 *
 * @package NG_Auth
 */

class NG_Auth_Providers_SmsAero extends NG_Auth_Providers_SMS
{
    public function get_id(): string
    {
        return 'smsaero';
    }

    public function get_name(): string
    {
        return __('SMS Aero', 'ng-auth');
    }

    public function get_description(): string
    {
        return __('Подтверждение через SMS-сервис SMS Aero.', 'ng-auth');
    }

    public function get_logo_url(): string
    {
        return 'https://smsaero.ru/favicon.ico';
    }

    public function get_provider_url(): string
    {
        return 'https://smsaero.ru';
    }

    public function get_instructions(): string
    {
        return __('1. Зарегистрируйтесь на smsaero.ru. 2. В личном кабинете создайте API-ключ (Настройки → API). 3. Укажите email и API-ключ в поле ниже в формате email:api_key. 4. Включите провайдера.', 'ng-auth')
            . "\n\n"
            . __('Подпись отправителя: можно указать «SMS Aero» (системная, без одобрения) или собственную (требуется модерация в личном кабинете).', 'ng-auth')
            . "\n\n"
            . __('Режим тестирования: запрос к API не выполняется, SMS не отправляется. Код записывается в Журнал (действие «Тест OTP»).', 'ng-auth');
    }

    public function init_form_fields(): array
    {
        // Наследуем базовые поля SMS-провайдера: api_key, api_secret, sender_name, priority, enabled.
        $fields = parent::init_form_fields();
        $prefix = 'provider_' . $this->get_id();
        $settings = get_option('ng_auth_settings', []);

        // Переопределяем label для api_key под формат SMS Aero.
        $fields["{$prefix}_api_key"] = [
            'label' => __('API-ключ (email:api_key)', 'ng-auth'),
            'type' => 'text',
            'default' => $settings["{$prefix}_api_key"] ?? '',
            'required' => true,
        ];

        // Добавляем специфичные поля SMS Aero.
        $fields["{$prefix}_sign"] = [
            'label' => __('Подпись отправителя', 'ng-auth'),
            'type' => 'text',
            'default' => $settings["{$prefix}_sign"] ?? 'SMS Aero',
        ];

        return $fields;
    }

    /**
     * Отправка SMS через SMS Aero API v2.
     *
     * Эндпоинт: POST https://gate.smsaero.ru/v2/sms/send
     * Аутентификация: HTTP Basic Auth (email:api_key в формате base64).
     * Тело запроса: JSON {"number": "79166826219", "text": "...", "sign": "SMS Aero"}.
     *
     * Особенности API:
     * - Номер телефона передаётся без знака «+», только цифры (10-11 знаков).
     * - Подпись «SMS Aero» — системная, доступна без одобрения.
     * - При отсутствии одобренной подписи сообщения могут уходить на модерацию (extendStatus: moderation).
     * - Кодировка сообщения: UTF-8, передаётся как есть в JSON.
     *
     * Тестовый режим: реальный запрос к API не выполняется, SMS не отправляется.
     * Факт «отправки» записывается в Журнал с пометкой «test_otp».
     *
     * @param string $phone   Номер телефона получателя (только цифры, 10-11 знаков).
     * @param string $message Текст SMS-сообщения.
     * @return bool true при успешном ответе API (success: true).
     */
    protected function send_sms(string $phone, string $message): bool
    {
        $api_key = $this->get_option('api_key');
        $test_mode = (bool) ($this->get_option('test_mode', false));

        if ($test_mode) {
            NG_Auth_Log_Logger::info('SMS Aero test mode: skipping real send', [
                'phone' => $phone,
                'message' => $message,
            ]);
            return true;
        }

        if ('' === $api_key) {
            NG_Auth_Log_Logger::warning('SMS Aero: API key not configured');
            return false;
        }

        // Приводим номер к чистому формату (без +, скобок, пробелов).
        $phone = preg_replace('/[^0-9]/', '', $phone);

        $sign = $this->get_option('sign', 'SMS Aero');

        $http = new NG_Auth_Http_Client(10);
        $response = $http->post_json('https://gate.smsaero.ru/v2/sms/send', [
            'number' => $phone,
            'text' => $message,
            'sign' => $sign,
        ], [
            'Authorization' => 'Basic ' . base64_encode($api_key),
        ]);

        if (is_wp_error($response)) {
            NG_Auth_Log_Logger::error('SMS Aero HTTP request failed', [
                'error' => $response->get_error_message(),
            ]);
            return false;
        }

        $data = json_decode($response['body'], true);

        if (!is_array($data)) {
            NG_Auth_Log_Logger::error('SMS Aero invalid JSON response', [
                'body' => substr($response['body'], 0, 500),
            ]);
            return false;
        }

        $success = ($data['success'] ?? false) === true;

        if ($success) {
            $sms_id = $data['data']['id'] ?? 'unknown';
            NG_Auth_Log_Logger::info('SMS Aero send OK', [
                'id' => $sms_id,
                'phone' => $phone,
                'status' => $data['data']['extendStatus'] ?? 'unknown',
                'cost' => $data['data']['cost'] ?? 0,
            ]);
        } else {
            NG_Auth_Log_Logger::warning('SMS Aero send failed', [
                'http_code' => $response['code'],
                'api_message' => $data['message'] ?? 'unknown',
                'api_errors' => $data['data'] ?? [],
                'body' => substr($response['body'], 0, 500),
            ]);
        }

        return $success;
    }
}
