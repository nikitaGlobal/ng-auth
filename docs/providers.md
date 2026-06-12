# Руководство по созданию провайдера

## Интерфейс

Каждый провайдер должен реализовывать `NG_Auth_Provider_Interface`:

```php
interface NG_Auth_Provider_Interface {
    public function get_id(): string;
    public function get_name(): string;
    public function get_description(): string;
    public function is_available(): bool;
    public function get_admin_fields(): array;
    public function initiate_verification(WP_User $user): NG_Auth_Verification_Result;
    public function verify(WP_User $user, array $data): NG_Auth_Verification_Result;
}
```

## Базовые классы

- **NG_Auth_Providers_Base_Provider** — `get_option()`, `is_available()`, `get_priority()`
- **NG_Auth_Providers_SMS_Provider** — `send_sms()`, `initiate_verification()`, `verify()`

## Пример: SMS-провайдер

```php
class My_SMS_Provider extends NG_Auth_Providers_SMS_Provider {
    public function get_id(): string { return 'my_sms'; }
    public function get_name(): string { return 'My SMS'; }
    public function get_description(): string { return 'Мой SMS-провайдер'; }

    protected function send_sms(string $phone, string $message): bool {
        // Отправка SMS через API
        return true;
    }
}
```

## Пример: OAuth-провайдер (VK ID)

```php
class VK_ID_Provider extends NG_Auth_Providers_Base_Provider {
    public function get_id(): string { return 'vk_id'; }
    public function get_name(): string { return 'VK ID'; }
    public function get_description(): string { return 'Подтверждение через VK ID'; }

    public function initiate_verification(WP_User $user): NG_Auth_Verification_Result {
        $auth_url = 'https://id.vk.com/auth?client_id=...&redirect_uri=...';
        return NG_Auth_Verification_Result::redirect($auth_url);
    }

    public function verify(WP_User $user, array $data): NG_Auth_Verification_Result {
        // Обмен code на token, получение данных пользователя
        return NG_Auth_Verification_Result::success('Подтверждено');
    }

    public function get_admin_fields(): array { /* ... */ }
}
```

## Регистрация провайдера

```php
add_filter('ng_auth_register_providers', function(array $providers) {
    $providers[] = new My_SMS_Provider();
    $providers[] = new VK_ID_Provider();
    return $providers;
});
```
