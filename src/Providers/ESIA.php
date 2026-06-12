<?php

/**
 * Провайдер верификации через ЕСИА (Госуслуги).
 *
 * Реализует OAuth2-авторизацию через Единую систему идентификации
 * и аутентификации. Расширяет базовый класс провайдера, предоставляя
 * поля настроек для Client ID, Client Secret и эндпоинтов.
 *
 * После успешной авторизации через Госуслуги пользователь считается
 * подтверждённым.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_Providers_ESIA extends NG_Auth_Providers_Base
{
    /**
     * Идентификатор провайдера.
     *
     * @return string
     */
    public function get_id(): string
    {
        return 'esia';
    }

    /**
     * Читаемое название провайдера.
     *
     * @return string
     */
    public function get_name(): string
    {
        return __('ЕСИА / Госуслуги (экспериментальная возможность)', 'ng-auth');
    }

    /**
     * Описание провайдера.
     *
     * @return string
     */
    public function get_description(): string
    {
        return __('Экспериментальная возможность. Подтверждение личности через Единую систему идентификации и аутентификации (Госуслуги). Требует зарегистрированной мнемоники в СМЭВ.', 'ng-auth');
    }

    /**
     * URL логотипа провайдера.
     *
     * @return string
     */
    public function get_logo_url(): string
    {
        return 'https://esia.gosuslugi.ru/idp/static/img/favicon.ico';
    }

    /**
     * URL сайта провайдера.
     *
     * @return string
     */
    public function get_provider_url(): string
    {
        return 'https://esia.gosuslugi.ru';
    }

    /**
     * Инструкция по настройке провайдера.
     *
     * @return string
     */
    public function get_instructions(): string
    {
        return __('1. Зарегистрируйте информационную систему в ЕСИА через СМЭВ. 2. Получите мнемонику (Client ID) и ключ. 3. Укажите Redirect URI: ' . esc_url(home_url('/esia-callback')) . '. 4. Заполните поля ниже.', 'ng-auth');
    }

    /**
     * Поля настроек провайдера в админке.
     *
     * Включает поля для OAuth2: client_id, client_secret, эндпоинты,
     * redirect_uri, scope и приоритет.
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
            "{$prefix}_client_id" => [
                'label' => __('Client ID (Мнемоника)', 'ng-auth'),
                'type' => 'text',
                'default' => $settings["{$prefix}_client_id"] ?? '',
            ],
            "{$prefix}_client_secret" => [
                'label' => __('Client Secret', 'ng-auth'),
                'type' => 'password',
                'default' => $settings["{$prefix}_client_secret"] ?? '',
            ],
            "{$prefix}_auth_endpoint" => [
                'label' => __('Auth Endpoint', 'ng-auth'),
                'type' => 'readonly',
                'default' => $settings["{$prefix}_auth_endpoint"] ?? 'https://esia.gosuslugi.ru/aas/oauth2/ac',
            ],
            "{$prefix}_token_endpoint" => [
                'label' => __('Token Endpoint', 'ng-auth'),
                'type' => 'readonly',
                'default' => $settings["{$prefix}_token_endpoint"] ?? 'https://esia.gosuslugi.ru/aas/oauth2/te',
            ],
            "{$prefix}_redirect_uri" => [
                'label' => __('Redirect URI', 'ng-auth'),
                'type' => 'readonly',
                'default' => $settings["{$prefix}_redirect_uri"] ?? home_url('/esia-callback'),
            ],
            "{$prefix}_scope" => [
                'label' => __('Scope', 'ng-auth'),
                'type' => 'text',
                'default' => $settings["{$prefix}_scope"] ?? 'openid fullname',
            ],
            "{$prefix}_priority" => [
                'label' => __('Приоритет', 'ng-auth'),
                'type' => 'number',
                'default' => $settings["{$prefix}_priority"] ?? 10,
            ],
        ];
    }

    /**
     * Инициация верификации через ЕСИА.
     *
     * Формирует URL для перенаправления пользователя на портал Госуслуг
     * с параметрами OAuth2. Сохраняет состояние (state) в transient.
     *
     * @param WP_User $user Пользователь.
     * @return NG_Auth_Verification_Result Результат с URL перенаправления.
     */
    public function initiate_verification(WP_User $user): NG_Auth_Verification_Result
    {
        $client_id = $this->get_option('client_id');
        $auth_endpoint = $this->get_option('auth_endpoint', 'https://esia.gosuslugi.ru/aas/oauth2/ac');
        $redirect_uri = $this->get_option('redirect_uri', home_url('/esia-callback'));
        $scope = $this->get_option('scope', 'openid fullname');

        if ('' === $client_id) {
            return NG_Auth_Verification_Result::failure(
                __('ЕСИА не настроен. Обратитесь к администратору.', 'ng-auth')
            );
        }

        $state = wp_create_nonce('ng_auth_esia_' . $user->ID);
        set_transient('ng_auth_esia_state_' . $state, $user->ID, 600);

        $redirect = add_query_arg([
            'client_id' => $client_id,
            'response_type' => 'code',
            'redirect_uri' => $redirect_uri,
            'scope' => $scope,
            'state' => $state,
            'access_type' => 'online',
        ], $auth_endpoint);

        (new NG_Auth_Storage_User_Meta_Storage())->mark_pending($user, $this->get_id());

        return NG_Auth_Verification_Result::redirect(
            $redirect,
            __('Перенаправление на портал Госуслуг...', 'ng-auth')
        );
    }

    /**
     * Проверка результата авторизации ЕСИА.
     *
     * Принимает авторизационный код (code) и состояние (state) после
     * возврата пользователя с портала Госуслуг. Обменивает code на токен
     * и подтверждает пользователя.
     *
     * @param WP_User              $user Пользователь.
     * @param array<string, mixed> $data Данные с ключами 'code' и 'state'.
     * @return NG_Auth_Verification_Result Результат проверки.
     */
    public function verify(WP_User $user, array $data): NG_Auth_Verification_Result
    {
        $code = sanitize_text_field($data['code'] ?? '');
        $state = sanitize_text_field($data['state'] ?? '');

        if ('' === $code || '' === $state) {
            return NG_Auth_Verification_Result::failure(
                __('Неполные данные авторизации ЕСИА.', 'ng-auth')
            );
        }

        $stored_user_id = get_transient('ng_auth_esia_state_' . $state);
        if ((int) $stored_user_id !== $user->ID) {
            return NG_Auth_Verification_Result::failure(
                __('Нарушение состояния авторизации. Попробуйте снова.', 'ng-auth')
            );
        }

        delete_transient('ng_auth_esia_state_' . $state);

        $token_data = $this->exchange_code($code);
        if (null === $token_data) {
            return NG_Auth_Verification_Result::failure(
                __('Ошибка обмена кода авторизации.', 'ng-auth')
            );
        }

        $esia_uid = $token_data['sub'] ?? $token_data['user_id'] ?? '';
        if ('' !== $esia_uid) {
            update_user_meta($user->ID, 'ng_auth_esia_uid', $esia_uid);
        }

        $storage = new NG_Auth_Storage_User_Meta_Storage();
        $storage->mark_verified($user, $this->get_id());
        $storage->log_verification($user, $this->get_id(), 'verify', 'verified');

        return NG_Auth_Verification_Result::success(
            __('Подтверждение через ЕСИА успешно пройдено.', 'ng-auth')
        );
    }

    /**
     * Обмен авторизационного кода на токен доступа ЕСИА.
     *
     * Отправляет POST-запрос к token endpoint ЕСИА.
     *
     * @param string $code Авторизационный код от Госуслуг.
     * @return array|null Данные токена или null при ошибке.
     */
    private function exchange_code(string $code): ?array
    {
        $client_id = $this->get_option('client_id');
        $client_secret = $this->get_option('client_secret');
        $token_endpoint = $this->get_option('token_endpoint', 'https://esia.gosuslugi.ru/aas/oauth2/te');
        $redirect_uri = $this->get_option('redirect_uri', home_url('/esia-callback'));

        $http = new NG_Auth_Http_Client(10);
        $response = $http->post_form($token_endpoint, [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect_uri,
            'state' => wp_create_nonce('esia_token'),
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $data = json_decode($response['body'], true);

        if (isset($data['error'])) {
            NG_Auth_Log_Logger::warning('ESIA token exchange error', [
                'error' => $data['error_description'] ?? $data['error'],
            ]);
            return null;
        }

        return $data;
    }
}
