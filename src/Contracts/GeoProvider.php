<?php

declare(strict_types=1);

/**
 * Контракт гео-IP провайдера.
 *
 * @package NG_Auth
 */

interface NG_Auth_Contracts_GeoProvider
{
    public function get_id(): string;
    public function get_name(): string;
    public function get_description(): string;
    public function is_available(): bool;
    public function get_country(string $ip): string;
}
