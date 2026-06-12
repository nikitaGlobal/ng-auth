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

        $fields["{$prefix}_endpoint"] = [
            'label' => __('Endpoint URL', 'ng-auth'),
            'type' => 'readonly',
            'default' => $settings["{$prefix}_endpoint"] ?? 'https://api.exolve.com/v1/sms/send',
        ];

        return $fields;
    }

    /**
     * Отправка SMS через API МТС Exolve.
     *
     * Использует Bearer-аутентификацию и JSON-формат запроса.
     *
     * @param string $phone   Номер телефона получателя.
     * @param string $message Текст сообщения.
     * @return bool true в случае успешной отправки.
     */
    protected function send_sms(string $phone, string $message): bool
    {
        $api_key = $this->get_option('api_key');
        $api_secret = $this->get_option('api_secret');
        $endpoint = $this->get_option('endpoint', 'https://api.exolve.com/v1/sms/send');
        $sender = $this->get_option('sender_name', 'NG.Auth');

        if ('' === $api_key || '' === $api_secret) {
            NG_Auth_Log_Logger::warning('Exolve: API credentials not configured');
            return false;
        }

        $http = new NG_Auth_Http_Client(10);
        $response = $http->post_json($endpoint, [
            'from' => $sender,
            'to' => $phone,
            'text' => $message,
        ], [
            'Authorization' => 'Bearer ' . $api_key,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode($response['body'], true);
        $success = $response['code'] === 200 && empty($data['error']);

        if (!$success) {
            NG_Auth_Log_Logger::warning('Exolve SMS failed', [
                'code' => $response['code'],
                'body' => substr($response['body'], 0, 300),
            ]);
        }

        return $success;
    }
}
