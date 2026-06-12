<?php

/**
 * Обработчик регистрации пользователей NG Auth.
 *
 * Перехватывает создание новых пользователей, устанавливает начальный статус
 * верификации и перенаправляет на страницу подтверждения при необходимости.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_Core_Registration_Handler
{
    /** @var NG_Auth_Contracts_Provider[] */
    private array $providers;

    private NG_Auth_Storage_User_Meta_Storage $storage;
    private NG_Auth_Core_Mandatory_Service $mandatory_service;

    /**
     * Обработчик аутентификации (для единой генерации токенов).
     *
     * @var NG_Auth_Core_Authentication_Handler|null
     */
    private ?NG_Auth_Core_Authentication_Handler $auth_handler;

    public function __construct(
        array $providers,
        NG_Auth_Storage_User_Meta_Storage $storage,
        NG_Auth_Core_Mandatory_Service $mandatory_service,
        ?NG_Auth_Core_Authentication_Handler $auth_handler = null
    ) {
        $this->providers = $providers;
        $this->storage = $storage;
        $this->mandatory_service = $mandatory_service;
        $this->auth_handler = $auth_handler;

        $this->register_hooks();
    }

    /**
     * Регистрация хуков, связанных с регистрацией пользователей.
     *
     * @return void
     */
    private function register_hooks(): void
    {
        add_action('user_register', [$this, 'on_user_register'], 10, 1);
        add_filter('registration_errors', [$this, 'check_registration_errors'], 10, 3);
        add_action('register_new_user', [$this, 'on_register_new_user'], 10, 1);
    }

    /**
     * Проверка ошибок регистрации (фильтр).
     *
     * Может быть расширен для добавления кастомных проверок через фильтр.
     *
     * @param WP_Error $errors                Объект ошибок регистрации.
     * @param string   $sanitized_user_login  Санированное имя пользователя.
     * @param string   $user_email            Email пользователя.
     * @return WP_Error Объект ошибок (возможно, модифицированный).
     */
    public function check_registration_errors(WP_Error $errors, string $sanitized_user_login, string $user_email): WP_Error
    {
        return $errors;
    }

    /**
     * Действие при регистрации нового пользователя.
     *
     * Устанавливает статус unverified и перенаправляет на страницу
     * верификации, если она обязательна для пользователя.
     *
     * @param int $user_id ID зарегистрированного пользователя.
     * @return void
     */
    public function on_user_register(int $user_id): void
    {
        $user = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            return;
        }

        if (apply_filters('ng_auth_skip_registration_verification', false, $user)) {
            return;
        }

        $this->storage->set_status($user, NG_AUTH_STATUS_UNVERIFIED);

        NG_Auth_Log_Logger::info('User registered, verification required', [
            'user_id' => $user->ID,
            'email' => $user->user_email,
        ]);

        if ($this->mandatory_service->is_mandatory_for_user($user)) {
            $this->redirect_to_verification($user);
        }
    }

    /**
     * Действие на финальном этапе регистрации нового пользователя.
     *
     * Дополнительная проверка перед завершением процесса регистрации.
     *
     * @param int $user_id ID пользователя.
     * @return void
     */
    public function on_register_new_user(int $user_id): void
    {
        $user = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            return;
        }

        if ($this->mandatory_service->is_mandatory_for_user($user)
            && !$this->storage->is_verified($user)
            && !apply_filters('ng_auth_skip_registration_verification', false, $user)
        ) {
            $this->redirect_to_verification($user);
        }
    }

    /**
     * Перенаправление пользователя на страницу верификации.
     *
     * Генерирует верификационный nonce и выполняет wp_safe_redirect.
     *
     * @param WP_User $user Пользователь.
     * @return void
     */
    private function redirect_to_verification(WP_User $user): void
    {
        if ($this->auth_handler) {
            $token = $this->auth_handler->generate_verification_token($user);
        } else {
            $token = wp_create_nonce('ng_auth_verify_' . $user->ID);
        }

        // Сохраняем URL страницы, с которой пришёл пользователь.
        $return_url = isset($_SERVER['HTTP_REFERER'])
            ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']))
            : (isset($_SERVER['REQUEST_URI']) ? home_url(wp_unslash($_SERVER['REQUEST_URI'])) : home_url());
        update_user_meta($user->ID, 'ng_auth_return_url', $return_url);

        $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);
        $url = home_url("/{$slug}/?token={$token}&user_id={$user->ID}");

        NG_Auth_Log_Logger::info('Registration: redirecting to verification', [
            'user_id' => $user->ID,
            'token_type' => $this->auth_handler ? 'hmac' : 'nonce',
            'url' => $url,
        ]);

        wp_safe_redirect(esc_url_raw($url));
        exit;
    }

    /**
     * Генерация верификационного nonce для пользователя.
     *
     * @param WP_User $user Пользователь.
     * @return string Сгенерированный nonce.
     */
    public function generate_verification_nonce(WP_User $user): string
    {
        return wp_create_nonce('ng_auth_verify_' . $user->ID);
    }

    /**
     * Проверка валидности верификационного nonce.
     *
     * @param WP_User $user  Пользователь.
     * @param string  $nonce Nonce для проверки.
     * @return bool true, если nonce действителен.
     */
    public function validate_verification_nonce(WP_User $user, string $nonce): bool
    {
        return (bool) wp_verify_nonce($nonce, 'ng_auth_verify_' . $user->ID);
    }
}
