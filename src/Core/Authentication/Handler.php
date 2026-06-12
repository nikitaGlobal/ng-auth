<?php

/**
 * Обработчик аутентификации NG Auth.
 *
 * Перехватывает процесс входа пользователя в систему и проверяет,
 * прошёл ли пользователь обязательную верификацию личности.
 * При отсутствии верификации блокирует вход и перенаправляет
 * на страницу подтверждения.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_Core_Authentication_Handler
{
    /** @var NG_Auth_Contracts_Provider[] */
    private array $providers;

    /**
     * Хранилище мета-данных пользователя.
     *
     * @var NG_Auth_Storage_User_Meta_Storage
     */
    private NG_Auth_Storage_User_Meta_Storage $storage;

    /**
     * Сервис проверки обязательности верификации.
     *
     * @var NG_Auth_Core_Mandatory_Service
     */
    private NG_Auth_Core_Mandatory_Service $mandatory_service;

    /**
     * Конструктор.
     *
     * @param NG_Auth_Contracts_Provider[]          $providers         Массив провайдеров верификации.
     * @param NG_Auth_Storage_User_Meta_Storage     $storage           Хранилище мета-данных.
     * @param NG_Auth_Core_Mandatory_Service        $mandatory_service Сервис обязательной верификации.
     */
    public function __construct(
        array $providers,
        NG_Auth_Storage_User_Meta_Storage $storage,
        NG_Auth_Core_Mandatory_Service $mandatory_service
    ) {
        $this->providers = $providers;
        $this->storage = $storage;
        $this->mandatory_service = $mandatory_service;

        $this->register_hooks();
    }

    /**
     * Регистрация хуков процесса аутентификации.
     *
     * @return void
     */
    private function register_hooks(): void
    {
        add_filter('authenticate', [$this, 'check_authentication'], 100, 3);
        add_filter('wp_authenticate_user', [$this, 'validate_user_verification'], 100, 2);
        add_action('wp_login', [$this, 'after_login'], 10, 2);
    }

    /**
     * Проверка статуса пользователя на этапе аутентификации.
     *
     * Если пользователь заблокирован — возвращает WP_Error.
     * В остальных случаях пропускает пользователя дальше по цепочке.
     *
     * @param null|WP_User|WP_Error $user     Объект пользователя (или ошибка).
     * @param string                $username Логин пользователя.
     * @param string                $password Пароль пользователя.
     * @return null|WP_User|WP_Error
     */
    public function check_authentication($user, string $username, string $password)
    {
        if (!$user instanceof WP_User) {
            return $user;
        }

        if (!$this->mandatory_service->is_mandatory_for_user($user)) {
            return $user;
        }

        if ($this->storage->is_verified($user)) {
            return $user;
        }

        if ($this->storage->get_status($user) === NG_AUTH_STATUS_BLOCKED) {
            NG_Auth_Log_Logger::warning('Blocked user attempted login', ['user_id' => $user->ID]);
            return new WP_Error(
                'ng_auth_blocked',
                __('Ваша учётная запись заблокирована. Обратитесь к администратору.', 'ng-auth')
            );
        }

        // Проверяем локаут email/телефона (transient-блокировка после превышения попыток OTP)
        $lockout = new NG_Auth_Core_Lockout_Service();
        if ($lockout->is_email_locked($user->user_email)) {
            $remaining = $lockout->email_lock_remaining($user->user_email);
            NG_Auth_Log_Logger::warning('Locked-out user attempted login', [
                'user_id' => $user->ID,
                'email' => $user->user_email,
                'remaining' => $remaining,
            ]);
            return new WP_Error(
                'ng_auth_locked_out',
                sprintf(
                    __('Превышено число попыток. Email и телефон заблокированы. Попробуйте через %d мин.', 'ng-auth'),
                    (int) ceil($remaining / 60)
                )
            );
        }

        $phone_raw = get_user_meta($user->ID, 'ng_auth_phone_raw', true);
        if ('' !== $phone_raw && $lockout->is_phone_locked($phone_raw)) {
            $remaining = $lockout->phone_lock_remaining($phone_raw);
            NG_Auth_Log_Logger::warning('Locked-out user attempted login (phone)', [
                'user_id' => $user->ID,
                'remaining' => $remaining,
            ]);
            return new WP_Error(
                'ng_auth_locked_out',
                sprintf(
                    __('Превышено число попыток. Email и телефон заблокированы. Попробуйте через %d мин.', 'ng-auth'),
                    (int) ceil($remaining / 60)
                )
            );
        }

        return $user;
    }

    /**
     * Валидация пользователя перед завершением аутентификации.
     *
     * Если верификация обязательна, но не пройдена — перенаправляет
     * на страницу подтверждения.
     *
     * @param WP_User $user Пользователь.
     * @return WP_User Объект пользователя (если проверка пройдена).
     */
    public function validate_user_verification(WP_User $user): WP_User
    {
        if (!$this->mandatory_service->is_mandatory_for_user($user)) {
            return $user;
        }

        if ($this->storage->is_verified($user)) {
            return $user;
        }

        if (apply_filters('ng_auth_skip_authentication_check', false, $user)) {
            return $user;
        }

        $token = $this->generate_verification_token($user);
        $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);

        // Передаём return_url явно, потому что HTTP_REFERER теряется при двойном редиректе.
        $return_url = isset($_SERVER['HTTP_REFERER'])
            ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']))
            : (isset($_SERVER['REQUEST_URI']) ? home_url(wp_unslash($_SERVER['REQUEST_URI'])) : home_url());
        update_user_meta($user->ID, 'ng_auth_return_url', $return_url);

        $url = home_url("/{$slug}/?token={$token}&user_id={$user->ID}");

        wp_safe_redirect(esc_url_raw($url));
        exit;
    }

    /**
     * Действие после успешного входа.
     *
     * Выполняет принудительный выход, если пользователь не прошёл
     * верификацию, и перенаправляет на страницу подтверждения.
     *
     * @param string  $user_login Логин пользователя.
     * @param WP_User $user       Пользователь.
     * @return void
     */
    public function after_login(string $user_login, WP_User $user): void
    {
        if (!$this->mandatory_service->is_mandatory_for_user($user)) {
            return;
        }

        if (!$this->storage->is_verified($user) && !apply_filters('ng_auth_skip_authentication_check', false, $user)) {
            wp_logout();
            wp_destroy_current_session();
            wp_clear_auth_cookie();

            $token = $this->generate_verification_token($user);
            $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);

            // Сохраняем URL страницы, с которой пришёл пользователь.
            $return_url = isset($_SERVER['HTTP_REFERER'])
                ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']))
                : (isset($_SERVER['REQUEST_URI']) ? home_url(wp_unslash($_SERVER['REQUEST_URI'])) : home_url());
            update_user_meta($user->ID, 'ng_auth_return_url', $return_url);

            $url = home_url("/{$slug}/?token={$token}&user_id={$user->ID}");

            wp_safe_redirect(esc_url_raw($url));
            exit;
        }
    }

    /**
     * Генерация верификационного токена.
     *
     * Токен живёт 10 минут (NG_AUTH_VERIFY_TOKEN_TTL).
     * Сохраняется в user_meta вместе с временем создания.
     * Номер телефона блокируется: после первой отправки OTP он фиксируется в токене,
     * и сменить его можно только через новый вход.
     *
     * @param WP_User $user  Пользователь.
     * @param string  $phone Номер телефона для привязки (опционально).
     * @return string Сгенерированный токен.
     */
    public function generate_verification_token(WP_User $user, string $phone = ''): string
    {
        $ttl = defined('NG_AUTH_VERIFY_TOKEN_TTL') ? NG_AUTH_VERIFY_TOKEN_TTL : 600;
        $expiry = time() + $ttl;
        $salt = wp_salt('nonce');

        $payload = implode('|', [
            $user->ID,
            $expiry,
            $phone,
            wp_hash($user->user_login),
        ]);

        $hash = hash_hmac('sha256', $payload, $salt);
        $token = base64_encode($payload . '|' . $hash);

        update_user_meta($user->ID, 'ng_auth_verify_token', wp_hash_password($token));
        update_user_meta($user->ID, 'ng_auth_verify_token_expiry', $expiry);

        if ('' !== $phone) {
            $this->storage->set_phone($user, $phone);
            update_user_meta($user->ID, 'ng_auth_verify_phone_hash', wp_hash($phone));
        }

        return $token;
    }

    /**
     * Обновление токена с привязкой телефона (после отправки OTP).
     *
     * @param WP_User $user  Пользователь.
     * @param string  $phone Номер телефона.
     * @return string Новый токен.
     */
    public function update_verification_token_phone(WP_User $user, string $phone): string
    {
        return $this->generate_verification_token($user, $phone);
    }

    /**
     * Получение сохранённого URL для возврата.
     *
     * @param WP_User $user Пользователь.
     * @return string
     */
    public function get_return_url(WP_User $user): string
    {
        $url = get_user_meta($user->ID, 'ng_auth_return_url', true);
        return '' !== $url ? (string) $url : wp_login_url();
    }

    /**
     * Проверка валидности верификационного токена.
     *
     * Проверяет:
     * - Формат токена.
     * - Срок действия (10 минут).
     * - Соответствие user_id и хеша пользователя.
     * - Соответствие сохранённому хешу в user_meta.
     * - Если в токене есть телефон — проверяет, что он не менялся.
     *
     * @param WP_User $user  Пользователь.
     * @param string  $token Токен для проверки.
     * @return bool true, если токен действителен.
     */
    public function validate_verification_token(WP_User $user, string $token): bool
    {
        $decoded = base64_decode($token, true);

        if (false === $decoded) {
            return false;
        }

        $parts = explode('|', $decoded);

        if (5 !== count($parts)) {
            return false;
        }

        [$token_user_id, $expiry, $phone, $login_hash, $hash] = $parts;

        $expiry = (int) $expiry;

        // Проверка срока.
        if ($expiry < time()) {
            NG_Auth_Log_Logger::info('Verification token expired', [
                'user_id' => $user->ID,
                'expired_at' => date('Y-m-d H:i:s', $expiry),
            ]);
            return false;
        }

        // Проверка user_id.
        if ((int) $token_user_id !== $user->ID) {
            return false;
        }

        // Проверка хеша пользователя (на случай смены логина).
        if ($login_hash !== wp_hash($user->user_login)) {
            return false;
        }

        // Проверка подписи.
        $salt = wp_salt('nonce');
        $payload = implode('|', [$token_user_id, $expiry, $phone, $login_hash]);
        $expected_hash = hash_hmac('sha256', $payload, $salt);

        if (!hash_equals($expected_hash, $hash)) {
            return false;
        }

        // Проверка сохранённого хеша токена в user_meta.
        $stored_hash = get_user_meta($user->ID, 'ng_auth_verify_token', true);
        if ('' === $stored_hash || !wp_check_password($token, $stored_hash)) {
            return false;
        }

        // Проверка привязки телефона: если в токене есть телефон, он не должен меняться.
        if ('' !== $phone) {
            $saved_phone_hash = get_user_meta($user->ID, 'ng_auth_verify_phone_hash', true);
            if ('' !== $saved_phone_hash && $saved_phone_hash !== wp_hash($phone)) {
                NG_Auth_Log_Logger::warning('Phone mismatch in verification token', [
                    'user_id' => $user->ID,
                ]);
                return false;
            }
        }

        return true;
    }
}
