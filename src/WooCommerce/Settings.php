<?php

declare(strict_types=1);

/**
 * Интеграция NG Auth с настройками WooCommerce.
 *
 * Добавляет вкладку «NG Авторизация» в WooCommerce → Настройки → Интеграция.
 * Позволяет управлять настройками верификации прямо из WooCommerce.
 *
 * @package NG_Auth
 */

class NG_Auth_WooCommerce_Settings extends WC_Integration
{
    public function __construct()
    {
        $this->id = 'ng_auth';
        $this->method_title = __('NG Авторизация', 'ng-auth');
        $this->method_description = __(
            'Настройки обязательного подтверждения личности для WooCommerce.',
            'ng-auth'
        );

        $this->init_form_fields();
        $this->init_settings();

        add_action('woocommerce_update_options_integration_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields(): void
    {
        $settings = get_option('ng_auth_settings', []);

        $this->form_fields = [
            'enable_mandatory' => [
                'title' => __('Обязательное подтверждение', 'ng-auth'),
                'type' => 'checkbox',
                'label' => __('Включить обязательное подтверждение личности', 'ng-auth'),
                'default' => $settings['enable_mandatory'] ?? true,
            ],
            'require_russian_ip' => [
                'title' => __('Гео-IP фильтр', 'ng-auth'),
                'type' => 'checkbox',
                'label' => __('Требовать подтверждение только для IP из РФ', 'ng-auth'),
                'default' => $settings['require_russian_ip'] ?? true,
            ],
            'new_user_mode' => [
                'title' => __('Режим для новых пользователей', 'ng-auth'),
                'type' => 'select',
                'options' => [
                    'soft' => __('Ограниченный доступ', 'ng-auth'),
                    'hard' => __('Без доступа', 'ng-auth'),
                ],
                'default' => $settings['new_user_mode'] ?? 'soft',
            ],
        ];
    }

    public function process_admin_options(): void
    {
        $all_settings = get_option('ng_auth_settings', []);
        $all_settings['enable_mandatory'] = !empty($this->get_option('enable_mandatory'));
        $all_settings['require_russian_ip'] = !empty($this->get_option('require_russian_ip'));
        $all_settings['new_user_mode'] = sanitize_key((string) $this->get_option('new_user_mode', 'soft'));
        update_option('ng_auth_settings', $all_settings);
    }
}
