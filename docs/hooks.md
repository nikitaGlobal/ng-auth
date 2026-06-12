# Хуки и фильтры NG Auth

## Регистрация провайдеров

| Хук | Тип | Описание |
|---|---|---|
| `ng_auth_register_providers` | filter | Регистрация провайдеров (массив `NG_Auth_Provider_Interface[]`) |
| `ng_auth_available_providers` | filter | Фильтрация доступных для текущего пользователя |
| `ng_auth_provider_priority` | filter | Изменение приоритета провайдера |
| `ng_auth_default_provider` | filter | Провайдер по умолчанию (string ID) |

## Обязательность

| Хук | Тип | Описание |
|---|---|---|
| `ng_auth_is_mandatory_for_user` | filter | Программное управление обязательностью (bool, WP_User) |

## Пропуск проверок

| Хук | Тип | Описание |
|---|---|---|
| `ng_auth_skip_registration_verification` | filter | Пропустить верификацию при регистрации |
| `ng_auth_skip_authentication_check` | filter | Пропустить проверку при входе |

## UI

| Хук | Тип | Описание |
|---|---|---|
| `ng_auth_verify_slug` | filter | Изменение slug страницы верификации (по умолчанию `verify`) |
| `ng_auth_verification_redirect` | filter | URL после успешной верификации |
| `ng_auth_verification_page` | action | Действие на странице верификации |
| `ng_auth_unverified_allowed_endpoints` | filter | Разрешённые WooCommerce endpoints для неподтверждённых |
| `ng_auth_user_phone` | filter | Номер телефона пользователя (если не из мета-полей) |

## Администрирование

| Хук | Тип | Описание |
|---|---|---|
| `ng_auth_provider_admin_fields` | filter | Поля провайдера в админке |

## Приватность

| Хук | Тип | Описание |
|---|---|---|
| `ng_auth_personal_data_eraser` | filter | Интеграция с WordPress Personal Data Erasure |
| `ng_auth_personal_data_exporter` | filter | Интеграция с WordPress Personal Data Export |

## Cron

| Хук | Тип | Описание |
|---|---|---|
| `ng_auth_reminder_cron` | action | Обработчик cron-задачи рассылки |
| `ng_auth_reminder_interval` | filter | Кастомный интервал cron |
