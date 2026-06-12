<?php

declare(strict_types=1);

/**
 * Гео-IP провайдер НОЦ (geoip.noc.gov.ru).
 *
 * Национальный координационный центр по компьютерным инцидентам.
 * Бесплатный, без API-ключа.
 *
 * @package NG_Auth
 */

class NG_Auth_Geo_NocGov implements NG_Auth_Contracts_GeoProvider
{
    public function get_id(): string
    {
        return 'nocgov';
    }

    public function get_name(): string
    {
        return __('НОЦ (geoip.noc.gov.ru)', 'ng-auth');
    }

    public function get_description(): string
    {
        return __('Национальный координационный центр по компьютерным инцидентам.', 'ng-auth');
    }

    public function is_available(): bool
    {
        return true;
    }

    public function get_country(string $ip): string
    {
        $http = new NG_Auth_Http_Client(3);
        $response = $http->get('https://geoip.noc.gov.ru/');

        if (is_wp_error($response)) {
            return '';
        }

        $lines = explode("\n", trim($response['body']));
        return $lines[1] ?? '';
    }
}
