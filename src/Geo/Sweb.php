<?php

declare(strict_types=1);

/**
 * Гео-IP провайдер Sweb (sweb.ru).
 *
 * Принимает IP в параметре запроса.
 * Требует регистрации для получения API-ключа.
 *
 * @package NG_Auth
 */

class NG_Auth_Geo_Sweb implements NG_Auth_Contracts_GeoProvider
{
    public function get_id(): string
    {
        return 'sweb';
    }

    public function get_name(): string
    {
        return __('Sweb (sweb.ru)', 'ng-auth');
    }

    public function get_description(): string
    {
        return __('API геолокации Sweb.', 'ng-auth');
    }

    public function is_available(): bool
    {
        $api_key = $this->get_api_key();
        return '' !== $api_key;
    }

    public function get_country(string $ip): string
    {
        $api_key = $this->get_api_key();

        if ('' === $api_key) {
            return '';
        }

        $http = new NG_Auth_Http_Client(3);
        $response = $http->get(add_query_arg([
            'ip' => $ip,
            'key' => $api_key,
        ], 'https://sweb.ru/geoip/api'));

        if (is_wp_error($response)) {
            return '';
        }

        $data = json_decode($response['body'], true);
        return $data['country_code'] ?? $data['country'] ?? '';
    }

    private function get_api_key(): string
    {
        $settings = get_option('ng_auth_settings', []);
        return (string) ($settings['provider_sweb_api_key'] ?? '');
    }
}
