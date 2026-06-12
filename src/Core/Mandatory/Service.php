<?php

declare(strict_types=1);

/**
 * Сервис проверки обязательности верификации.
 *
 * @package NG_Auth
 */

class NG_Auth_Core_Mandatory_Service
{
    /**
     * Проверяет, обязательна ли верификация для пользователя.
     *
     * @param WP_User $user Пользователь.
     * @return bool
     */
    public function is_mandatory_for_user(WP_User $user): bool
    {
        if (apply_filters('ng_auth_skip_admin_verification', true) && user_can($user, 'manage_options')) {
            return apply_filters('ng_auth_is_mandatory_for_user', false, $user);
        }

        $config = NG_Auth_Config::instance();

        $globally_enabled = $config->get_bool('enable_mandatory', false);
        if (!$globally_enabled) {
            return apply_filters('ng_auth_is_mandatory_for_user', false, $user);
        }

        if (!$this->has_available_providers()) {
            return apply_filters('ng_auth_is_mandatory_for_user', false, $user);
        }

        $selected_roles = $config->get_array('selected_roles', []);
        if (!empty($selected_roles)) {
            $user_roles = $user->roles;
            $has_role = !empty(array_intersect($user_roles, $selected_roles));
            if (!$has_role) {
                return apply_filters('ng_auth_is_mandatory_for_user', false, $user);
            }
        }

        $require_russian_ip = $config->get_bool('require_russian_ip', false);
        if ($require_russian_ip && !$this->is_russian_ip()) {
            return apply_filters('ng_auth_is_mandatory_for_user', false, $user);
        }

        return apply_filters('ng_auth_is_mandatory_for_user', true, $user);
    }

    /**
     * Проверяет, российский ли IP у клиента.
     *
     * @return bool
     */
    private function is_russian_ip(): bool
    {
        $ip = $this->get_client_ip();

        if ('127.0.0.1' === $ip || '::1' === $ip) {
            return (bool) apply_filters('ng_auth_localhost_is_russian', false);
        }

        if (apply_filters('ng_auth_force_russian_ip', false)) {
            return true;
        }

        $cached = get_transient('ng_auth_ip_country_' . md5($ip));
        if (false !== $cached) {
            return 'RU' === $cached;
        }

        $country = $this->lookup_ip_country($ip);
        set_transient('ng_auth_ip_country_' . md5($ip), $country, DAY_IN_SECONDS);

        return 'RU' === $country;
    }

    /**
     * Получение IP клиента из заголовков.
     *
     * @return string
     */
    private function get_client_ip(): string
    {
        $sources = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($sources as $source) {
            if (!empty($_SERVER[$source])) {
                $ip_list = explode(',', sanitize_text_field(wp_unslash($_SERVER[$source])));
                $ip = trim($ip_list[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Определение страны через зарегистрированные гео-провайдеры.
     *
     * @param string $ip IP-адрес.
     * @return string Код страны.
     */
    private function lookup_ip_country(string $ip): string
    {
        $geo_providers = $this->get_geo_providers();

        foreach ($geo_providers as $provider) {
            $country = $provider->get_country($ip);
            if ('' !== $country) {
                return $country;
            }
        }

        return '';
    }

    /**
     * Проверяет, есть ли хотя бы один включённый провайдер верификации.
     *
     * @return bool
     */
    private function has_available_providers(): bool
    {
        $plugin = NG_Auth_Core_Plugin::instance();
        foreach ($plugin->get_providers() as $provider) {
            if ($provider->is_available()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return NG_Auth_Contracts_GeoProvider[]
     */
    private function get_geo_providers(): array
    {
        $defaults = [
            NG_Auth_Geo_NocGov::class,
            NG_Auth_Geo_Sweb::class,
        ];

        $class_names = apply_filters('ng_auth_register_geo_providers', $defaults);

        $providers = [];
        foreach ($class_names as $class) {
            if (class_exists($class)) {
                $instance = new $class();
                if ($instance instanceof NG_Auth_Contracts_GeoProvider && $instance->is_available()) {
                    $providers[] = $instance;
                }
            }
        }

        $settings = get_option('ng_auth_settings', []);
        $selected = $settings['geo_provider'] ?? '';

        usort($providers, function (NG_Auth_Contracts_GeoProvider $a, NG_Auth_Contracts_GeoProvider $b) use ($selected): int {
            if ($a->get_id() === $selected) {
                return -1;
            }
            if ($b->get_id() === $selected) {
                return 1;
            }
            return 0;
        });

        return $providers;
    }
}
