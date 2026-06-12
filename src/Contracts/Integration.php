<?php

declare(strict_types=1);

/**
 * Контракт интеграции с внешней платформой.
 *
 * @package NG_Auth
 */

interface NG_Auth_Contracts_Integration
{
    public function get_id(): string;
    public function get_name(): string;
    public function is_active(): bool;
    public function init(): void;
}
