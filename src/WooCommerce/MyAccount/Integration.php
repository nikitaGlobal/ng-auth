<?php

/**
 * Интеграция NG Auth с личным кабинетом WooCommerce (My Account).
 *
 * Добавляет раздел «Статус верификации» в личный кабинет,
 * показывает уведомление о необходимости подтверждения и
 * ограничивает доступ к разделам аккаунта для неподтверждённых пользователей.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_WooCommerce_MyAccount_Integration
{
    /** @var NG_Auth_Contracts_Provider[] */
    private array $providers;

    private NG_Auth_Storage_User_Meta_Storage $storage;
    private NG_Auth_Core_Mandatory_Service $mandatory_service;

    /**
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
     * Регистрация хуков My Account.
     *
     * @return void
     */
    private function register_hooks(): void
    {
        add_filter('woocommerce_login_credentials', [$this, 'check_login_credentials'], 100, 1);
        add_filter('woocommerce_account_menu_items', [$this, 'add_verification_status'], 100, 1);
        add_action('woocommerce_account_verification-status_endpoint', [$this, 'render_verification_status']);
        add_action('init', [$this, 'add_myaccount_endpoint']);
        add_action('woocommerce_before_my_account', [$this, 'show_verification_notice']);
        add_action('template_redirect', [$this, 'restrict_myaccount_for_unverified']);
    }

    /**
     * Проверка статуса верификации перед аутентификацией.
     *
     * Если пользователь не подтверждён — перенаправляет на страницу
     * верификации до завершения входа.
     *
     * @param array{user_login: string, user_password: string} $credentials Учётные данные.
     * @return array{user_login: string, user_password: string} Учётные данные.
     */
    public function check_login_credentials(array $credentials): array
    {
        $user = get_user_by('login', $credentials['user_login']);
        if (!$user instanceof WP_User) {
            $user = get_user_by('email', $credentials['user_login']);
        }

        if ($user instanceof WP_User
            && $this->mandatory_service->is_mandatory_for_user($user)
            && !$this->storage->is_verified($user)
            && !apply_filters('ng_auth_skip_authentication_check', false, $user)
        ) {
            if ($this->auth_handler) {
                $token = $this->auth_handler->generate_verification_token($user);
            } else {
                $token = wp_create_nonce('ng_auth_verify_' . $user->ID);
            }
            $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);

            wp_safe_redirect(home_url("/{$slug}/?token={$token}&user_id={$user->ID}"));
            exit;
        }

        return $credentials;
    }

    /**
     * Добавление пункта «Статус верификации» в меню личного кабинета.
     *
     * @param array<string, string> $items Пункты меню.
     * @return array<string, string> Обновлённые пункты меню.
     */
    public function add_verification_status(array $items): array
    {
        $items['verification-status'] = __('Статус верификации', 'ng-auth');
        return $items;
    }

    /**
     * Регистрация эндпоинта «verification-status» в WooCommerce.
     *
     * @return void
     */
    public function add_myaccount_endpoint(): void
    {
        add_rewrite_endpoint('verification-status', EP_ROOT | EP_PAGES);
    }

    /**
     * Отрисовка страницы статуса верификации в личном кабинете.
     *
     * Показывает текущий статус, провайдера, дату подтверждения
     * и кнопку для прохождения верификации.
     *
     * @return void
     */
    public function render_verification_status(): void
    {
        $user = wp_get_current_user();
        $status = $this->storage->get_status($user);
        $provider = $this->storage->get_provider($user);
        $verified_at = $this->storage->get_verified_at($user);

        $status_labels = [
            NG_AUTH_STATUS_UNVERIFIED => __('Не подтверждён', 'ng-auth'),
            NG_AUTH_STATUS_PENDING => __('В процессе', 'ng-auth'),
            NG_AUTH_STATUS_VERIFIED => __('Подтверждён', 'ng-auth'),
            NG_AUTH_STATUS_BLOCKED => __('Заблокирован', 'ng-auth'),
        ];

        echo '<div class="ng-auth-verification-status">';
        echo '<h3>' . esc_html__('Статус подтверждения личности', 'ng-auth') . '</h3>';
        echo '<p><strong>' . esc_html__('Статус:', 'ng-auth') . '</strong> '
            . esc_html($status_labels[$status] ?? $status) . '</p>';

        if ('' !== $provider) {
            echo '<p><strong>' . esc_html__('Провайдер:', 'ng-auth') . '</strong> '
                . esc_html($provider) . '</p>';
        }

        if ('' !== $verified_at) {
            echo '<p><strong>' . esc_html__('Дата подтверждения:', 'ng-auth') . '</strong> '
                . esc_html($verified_at) . '</p>';
        }

        if (NG_AUTH_STATUS_VERIFIED !== $status) {
            $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);
            echo '<p><a href="' . esc_url(home_url("/{$slug}/")) . '" class="button">'
                . esc_html__('Пройти подтверждение', 'ng-auth') . '</a></p>';
        }

        echo '</div>';
    }

    /**
     * Показ уведомления о необходимости верификации в личном кабинете.
     *
     * @return void
     */
    public function show_verification_notice(): void
    {
        $user = wp_get_current_user();

        if ($this->mandatory_service->is_mandatory_for_user($user)
            && !$this->storage->is_verified($user)
            && !apply_filters('ng_auth_skip_authentication_check', false, $user)
        ) {
            wc_print_notice(
                __('Для доступа ко всем функциям необходимо подтвердить личность.', 'ng-auth'),
                'notice'
            );
        }
    }

    /**
     * Ограничение доступа к разделам личного кабинета для неподтверждённых.
     *
     * Разрешает только указанные в фильтре ng_auth_unverified_allowed_endpoints
     * разделы. Для всех остальных — перенаправляет на страницу верификации.
     *
     * @return void
     */
    public function restrict_myaccount_for_unverified(): void
    {
        if (!is_account_page()) {
            return;
        }

        $user = wp_get_current_user();
        if (0 === $user->ID) {
            return;
        }

        if (!$this->mandatory_service->is_mandatory_for_user($user)) {
            return;
        }

        if ($this->storage->is_verified($user)) {
            return;
        }

        $allowed_endpoints = apply_filters('ng_auth_unverified_allowed_endpoints', [
            'verification-status',
            'customer-logout',
            'edit-account',
        ]);

        global $wp;
        $current_endpoint = WC()->query->get_current_endpoint();
        $current_page = $wp->request;

        foreach ($allowed_endpoints as $endpoint) {
            if (false !== strpos($current_page, $endpoint)) {
                return;
            }
        }

        if (!empty($current_endpoint) && !in_array($current_endpoint, $allowed_endpoints, true)) {
            $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);
            wp_safe_redirect(home_url("/{$slug}/"));
            exit;
        }
    }
}
