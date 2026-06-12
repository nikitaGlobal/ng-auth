<?php

/**
 * Провайдер верификации МТС Exolve.
 *
 * Отправляет SMS через API МТС Exolve.
 * Расширяет базовый SMS-провайдер, добавляя специфичные поля
 * настроек и реализацию отправки через HTTP API.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_Providers_Exolve extends NG_Auth_Providers_SMS
{
    /**
     * Идентификатор провайдера.
     *
     * @return string
     */
    public function get_id(): string
    {
        return 'exolve';
    }

    /**
     * Читаемое название провайдера.
     *
     * @return string
     */
    public function get_name(): string
    {
        return __('МТС Exolve', 'ng-auth');
    }

    /**
     * Описание провайдера.
     *
     * @return string
     */
    public function get_description(): string
    {
        return __('Подтверждение через SMS-сервис МТС Exolve.', 'ng-auth');
    }

    /**
     * URL логотипа провайдера.
     *
     * @return string
     */
    public function get_logo_url(): string
    {
        return 'https://exolve.ru/favicon.ico';
    }

    /**
     * URL сайта провайдера.
     *
     * @return string
     */
    public function get_provider_url(): string
    {
        return 'https://exolve.ru';
    }

    /**
     * Инструкция по настройке провайдера.
     *
     * @return string
     */
    public function get_instructions(): string
    {
        return __('1. Зарегистрируйтесь на exolve.ru. 2. Получите API-ключ и секрет в личном кабинете. 3. Укажите их ниже и включите провайдер.', 'ng-auth');
    }

    /**
     * Поля настроек провайдера в админке.
     *
     * Расширяет базовые поля SMS-провайдера, добавляя endpoint URL.
     *
     * @return array<string, array{label: string, type: string, default: mixed}> Поля настроек.
     */
    public function init_form_fields(): array
    {
        $fields = parent::init_form_fields();
        $prefix = 'provider_' . $this->get_id();
        $settings = get_option('ng_auth_settings', []);

        // Переопределяем sender_name как обязательное поле отправителя.
        unset($fields["{$prefix}_sender_name"]);

        $fields["{$prefix}_alpha_name"] = [
            'label' => __('Альфа-имя или номер отправителя', 'ng-auth'),
            'type' => 'text',
            'default' => $settings["{$prefix}_alpha_name"] ?? '',
            'required' => true,
            'description' => __('Зарегистрированное альфа-имя или купленный номер в личном кабинете МТС Exolve.', 'ng-auth'),
        ];

        $fields["{$prefix}_endpoint"] = [
            'label' => __('Endpoint URL', 'ng-auth'),
            'type' => 'readonly',
            'default' => $settings["{$prefix}_endpoint"] ?? 'https://api.exolve.ru/messaging/v1/SendSMS',
        ];

        return $fields;
    }

    /**
     * Отправка SMS через API МТС Exolve.
     *
     * Документация: https://docs.exolve.ru/docs/ru/api-reference/sms-api/sending-sms/
     * Эндпоинт: POST https://api.exolve.ru/messaging/v1/SendSMS
     * Авторизация: Bearer {api_key}
     * Параметры: number (отправитель), destination (получатель), text (сообщение)
     *
     * @param string $phone   Номер телефона получателя.
     * @param string $message Текст сообщения.
     * @return bool true в случае успешной отправки.
     */
    protected function send_sms(string $phone, string $message): bool
    {
        $test_mode = (bool) ($this->get_option('test_mode', false));

        if ($test_mode) {
            NG_Auth_Log_Logger::info('Exolve test mode: skipping real send', [
                'phone' => $phone,
                'message' => $message,
            ]);
            return true;
        }

        $api_key = $this->get_option('api_key');
        $endpoint = $this->get_option('endpoint', 'https://api.exolve.ru/messaging/v1/SendSMS');
        $sender = $this->get_option('alpha_name', '');

        // Приводим номер к чистому формату (без +, скобок, пробелов).
        $phone = preg_replace('/[^0-9]/', '', $phone);
        // Если sender — номер, чистим; альфа-имя оставляем как есть.
        if (preg_match('/^\+?[0-9]/', $sender)) {
            $sender = preg_replace('/[^0-9]/', '', $sender);
        }

        if ('' === $api_key) {
            NG_Auth_Log_Logger::warning('Exolve: API key not configured');
            return false;
        }

        if ('' === $sender) {
            NG_Auth_Log_Logger::warning('Exolve: alpha name not configured');
            return false;
        }

        $http = new NG_Auth_Http_Client(10);
        $response = $http->post_json($endpoint, [
            'number' => $sender,
            'destination' => $phone,
            'text' => $message,
        ], [
            'Authorization' => 'Bearer ' . $api_key,
        ]);

        if (is_wp_error($response)) {
            NG_Auth_Log_Logger::error('Exolve HTTP request failed', [
                'error' => $response->get_error_message(),
            ]);
            return false;
        }

        $data = json_decode($response['body'], true);
        $success = $response['code'] === 200 && is_array($data) && !empty($data['message_id']);

        if ($success) {
            NG_Auth_Log_Logger::info('Exolve SMS sent OK', [
                'message_id' => $data['message_id'] ?? 'unknown',
                'phone' => $phone,
            ]);
        } else {
            NG_Auth_Log_Logger::warning('Exolve SMS failed', [
                'http_code' => $response['code'],
                'body' => substr($response['body'], 0, 500),
            ]);
        }

        return $success;
    }
}
