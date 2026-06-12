# Архитектура NG Auth

## Компоненты

### Ядро (Core)

- **Plugin** — точка входа, загрузка компонентов, управление хуками, активация/деактивация
- **Registration_Handler** — перехват регистрации WordPress, установка статуса `unverified`, редирект на верификацию
- **Authentication_Handler** — проверка статуса при входе, блокировка неподтверждённых, редирект
- **Mandatory_Service** — логика обязательности: глобальная/по ролям/по доменам/программная

### Провайдеры (Providers)

- **Interface** — контракт: `get_id`, `get_name`, `get_description`, `is_available`, `get_admin_fields`, `initiate_verification`, `verify`
- **Base_Provider** — абстрактный класс: `get_option`, `is_available`, `get_priority`
- **SMS_Provider** — абстрактный класс для SMS-провайдеров: `send_sms`, `initiate_verification`, `verify`
- **Exolve_Provider** — реализация для МТС Exolve
- **SmsAero_Provider** — реализация для SMS Aero
- **ESIA_Provider** — реализация для ЕСИА (OAuth 2.0)

### Хранение (Storage)

- **User_Meta_Storage** — работа с мета-полями пользователя, OTP, журнал
- **Verification_Result** — DTO результата верификации
- Отдельная таблица `wp_ng_auth_verification_log` — журнал попыток

### Админка (Admin)

- **Settings_Page** — страница настроек (`/wp-admin/admin.php?page=ng-auth-settings`)
- Секции: Общие, OTP, Шаблоны, Рассылка, Провайдеры

### Уведомления (Notifications)

- **Reminder_Service** — cron-обработчик рассылки напоминаний
- **Email_Channel** — отправка email-уведомлений
- **SMS_Channel** — отправка SMS-уведомлений через активного SMS-провайдера

### UI

- **Verification_Form** — страница верификации (`/verify/`), обработка AJAX, выбор провайдера
- Шаблоны: `verification-form.php`, `provider-selector.php`

### WooCommerce

- **Registration_Integration** — перехват регистрации WooCommerce, редирект
- **MyAccount_Integration** — интеграция с my-account, endpoint статуса, ограничение доступа

### CLI

- `wp ng-auth mark-unverified` — пометка пользователей
- `wp ng-auth send-reminders` — запуск рассылки
- `wp ng-auth status <id>` — статус пользователя
- `wp ng-auth verify <id>` — ручное подтверждение
- `wp ng-auth stats` — статистика

## Потоки данных

### Регистрация
1. WordPress `user_register` → Registration_Handler → статус `unverified`
2. Редирект на `/verify/?token=...&user_id=...`
3. Выбор провайдера → SMS / ЕСИА
4. Успех → статус `verified` → редирект в ЛК

### Вход
1. WordPress `authenticate` → Authentication_Handler → проверка статуса
2. `unverified` → сброс сессии → редирект на `/verify/`
3. `blocked` → ошибка входа
4. `verified` → штатный вход

### OTP
1. Генерация: `wp_hash_password(otp)` → хранение в `ng_auth_otp`
2. TTL: `time() + otp_ttl` → хранение в `ng_auth_otp_expiry`
3. Проверка: `wp_check_password(code, hash)`
4. Очистка: после использования или истечения
