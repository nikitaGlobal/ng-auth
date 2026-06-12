<?php

/**
 * Интеграция NG Auth с формой регистрации WooCommerce.
 *
 * Добавляет уведомление о необходимости верификации на форму регистрации,
 * устанавливает статус unverified для новых клиентов и перенаправляет
 * на страницу подтверждения после регистрации.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_WooCommerce_Registration_Integration
{
    /** @var NG_Auth_Contracts_Provider[] */
    private array $providers;

    private NG_Auth_Storage_User_Meta_Storage $storage;
    private NG_Auth_Core_Mandatory_Service $mandatory_service;

    /**
     * Обработчик аутентификации (для генерации HMAC-токенов).
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
     * Регистрация хуков WooCommerce, связанных с регистрацией.
     *
     * @return void
     */
    private function register_hooks(): void
    {
        add_action('woocommerce_register_form', [$this, 'add_verification_notice']);
        add_filter('woocommerce_registration_errors', [$this, 'validate_registration'], 10, 3);
        add_action('woocommerce_created_customer', [$this, 'on_customer_created'], 10, 1);
        add_filter('woocommerce_registration_redirect', [$this, 'registration_redirect'], 100, 1);
    }

    /**
     * Добавление уведомления о верификации на форму регистрации.
     *
     * Выводит текст, предупреждающий о необходимости подтверждения личности
     * после создания учётной записи.
     *
     * @return void
     */
    public function add_verification_notice(): void
    {
        $settings = get_option('ng_auth_settings', []);
        if (!empty($settings['enable_mandatory'])) {
            $notice = $settings['registration_notice']
                ?? __('После регистрации потребуется подтверждение личности.', 'ng-auth');
            echo '<p class="ng-auth-registration-notice">'
                . esc_html($notice)
                . '</p>';
        }
    }

    /**
     * Валидация ошибок регистрации WooCommerce.
     *
     * Может быть расширен для добавления кастомных проверок.
     *
     * @param WP_Error $errors   Объект ошибок.
     * @param string   $username Имя пользователя.
     * @param string   $email    Email пользователя.
     * @return WP_Error Объект ошибок.
     */
    public function validate_registration(WP_Error $errors, string $username, string $email): WP_Error
    {
        return $errors;
    }

    /**
     * Действие при создании клиента WooCommerce.
     *
     * Устанавливает начальный статус unverified для нового пользователя.
     *
     * @param int $customer_id ID созданного клиента.
     * @return void
     */
    public function on_customer_created(int $customer_id): void
    {
        $user = get_userdata($customer_id);
        if (!$user instanceof WP_User) {
            return;
        }

        if (apply_filters('ng_auth_skip_registration_verification', false, $user)) {
            return;
        }

        $this->storage->set_status($user, NG_AUTH_STATUS_UNVERIFIED);
    }

    /**
     * Перенаправление после регистрации в WooCommerce.
     *
     * Если верификация обязательна, но не пройдена — перенаправляет
     * на страницу подтверждения вместо стандартного редиректа.
     *
     * @param string|int $redirect URL или ID страницы редиректа.
     * @return string|int Исходный URL или URL страницы верификации.
     */
    public function registration_redirect($redirect)
    {
        $user_id = get_current_user_id();
        if (0 === $user_id) {
            return $redirect;
        }

        $user = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            return $redirect;
        }

        if (!$this->mandatory_service->is_mandatory_for_user($user)) {
            return $redirect;
        }

        if ($this->storage->is_verified($user)) {
            return $redirect;
        }

        if ($this->auth_handler) {
            $token = $this->auth_handler->generate_verification_token($user);
        } else {
            $handler = new NG_Auth_Core_Registration_Handler(
                $this->providers,
                $this->storage,
                $this->mandatory_service
            );
            $token = $handler->generate_verification_nonce($user);
        }
        $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);

        return home_url("/{$slug}/?token={$token}&user_id={$user->ID}");
    }
}
