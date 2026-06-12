<?php

declare(strict_types=1);

/**
 * Интеграция NG Auth с WooCommerce.
 *
 * @package NG_Auth
 */

class NG_Auth_Integrations_WooCommerce implements NG_Auth_Contracts_Integration
{
    public function get_id(): string
    {
        return 'woocommerce';
    }

    public function get_name(): string
    {
        return 'WooCommerce';
    }

    public function is_active(): bool
    {
        return in_array(
            'woocommerce/woocommerce.php',
            apply_filters('active_plugins', get_option('active_plugins')),
            true
        );
    }

    public function init(): void
    {
        $plugin = NG_Auth_Core_Plugin::instance();
        $providers = $plugin->get_providers();
        $storage = $plugin->get_storage();
        $mandatory = $plugin->get_mandatory_service();
        $auth_handler = $plugin->get_authentication_handler();

        new NG_Auth_WooCommerce_Registration_Integration($providers, $storage, $mandatory, $auth_handler);
        new NG_Auth_WooCommerce_MyAccount_Integration($providers, $storage, $mandatory, $auth_handler);

        add_filter('woocommerce_integrations', [$this, 'add_settings']);
    }

    /**
     * Регистрирует вкладку в WooCommerce → Настройки → Интеграция.
     *
     * @param array $integrations
     * @return array
     */
    public function add_settings(array $integrations): array
    {
        $integrations[] = NG_Auth_WooCommerce_Settings::class;
        return $integrations;
    }
}
