<?php

/**
 * Хранилище данных верификации в пользовательских мета-полях.
 *
 * Реализует все операции CRUD для статусов верификации, OTP-кодов,
 * логов верификации и персональных данных пользователя через
 * стандартный механизм user_meta WordPress.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_Storage_User_Meta_Storage
{
    /**
     * Получение статуса верификации пользователя.
     *
     * Результат кешируется в статическом массиве на время одного запроса.
     *
     * @param WP_User $user Пользователь.
     * @return string Статус верификации (одна из констант NG_AUTH_STATUS_*).
     */
    public function get_status(WP_User $user): string
    {
        static $cache = [];
        $uid = $user->ID;

        if (isset($cache[$uid])) {
            return $cache[$uid];
        }

        $status = (string) get_user_meta($uid, 'ng_auth_status', true);
        if ('' === $status) {
            $status = NG_AUTH_STATUS_UNVERIFIED;
        }

        $cache[$uid] = $status;
        return $status;
    }

    /**
     * Установка статуса верификации пользователя.
     *
     * @param WP_User $user   Пользователь.
     * @param string  $status Статус (одна из констант NG_AUTH_STATUS_*).
     * @return void
     */
    public function set_status(WP_User $user, string $status): void
    {
        update_user_meta($user->ID, 'ng_auth_status', $status);
    }

    /**
     * Проверка, подтверждён ли пользователь.
     *
     * @param WP_User $user Пользователь.
     * @return bool true, если статус равен NG_AUTH_STATUS_VERIFIED.
     */
    public function is_verified(WP_User $user): bool
    {
        return $this->get_status($user) === NG_AUTH_STATUS_VERIFIED;
    }

    /**
     * Отметка пользователя как подтверждённого.
     *
     * Записывает статус, провайдера, время подтверждения
     * и очищает OTP-данные.
     *
     * @param WP_User $user     Пользователь.
     * @param string  $provider Идентификатор провайдера, через которого пройдена верификация.
     * @return void
     */
    public function mark_verified(WP_User $user, string $provider): void
    {
        update_user_meta($user->ID, 'ng_auth_status', NG_AUTH_STATUS_VERIFIED);
        update_user_meta($user->ID, 'ng_auth_provider', $provider);
        update_user_meta($user->ID, 'ng_auth_verified_at', current_time('mysql'));
        delete_user_meta($user->ID, 'ng_auth_otp');
        delete_user_meta($user->ID, 'ng_auth_otp_expiry');
        delete_user_meta($user->ID, 'ng_auth_otp_attempts');
    }

    /**
     * Отметка пользователя как ожидающего подтверждения.
     *
     * @param WP_User $user     Пользователь.
     * @param string  $provider Идентификатор провайдера.
     * @return void
     */
    public function mark_pending(WP_User $user, string $provider): void
    {
        update_user_meta($user->ID, 'ng_auth_status', NG_AUTH_STATUS_PENDING);
        update_user_meta($user->ID, 'ng_auth_provider', $provider);
    }

    /**
     * Блокировка пользователя.
     *
     * @param WP_User $user Пользователь.
     * @return void
     */
    public function mark_blocked(WP_User $user): void
    {
        update_user_meta($user->ID, 'ng_auth_status', NG_AUTH_STATUS_BLOCKED);
    }

    /**
     * Сохранение хешированного номера телефона пользователя.
     *
     * @param WP_User $user  Пользователь.
     * @param string  $phone Номер телефона.
     * @return void
     */
    public function set_phone(WP_User $user, string $phone): void
    {
        update_user_meta($user->ID, 'ng_auth_phone', wp_hash($phone));
        update_user_meta($user->ID, 'ng_auth_phone_raw', preg_replace('/[^0-9]/', '', $phone));
    }

    /**
     * Получение хеша номера телефона пользователя.
     *
     * @param WP_User $user Пользователь.
     * @return string Хеш номера телефона.
     */
    public function get_phone_hash(WP_User $user): string
    {
        return (string) get_user_meta($user->ID, 'ng_auth_phone', true);
    }

    /**
     * Генерация и сохранение OTP-кода для пользователя.
     *
     * Создаёт случайный числовой код заданной длины, сохраняет его хеш
     * (wp_hash_password), время истечения и счётчик попыток.
     *
     * @param WP_User $user Пользователь.
     * @return string Сгенерированный OTP-код (открытый текст).
     */
    public function generate_otp(WP_User $user): string
    {
        $settings = get_option('ng_auth_settings', []);
        $length = (int) ($settings['otp_length'] ?? 6);
        $ttl = (int) ($settings['otp_ttl'] ?? 300);

        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= (string) random_int(0, 9);
        }

        update_user_meta($user->ID, 'ng_auth_otp', wp_hash_password($otp));
        update_user_meta($user->ID, 'ng_auth_otp_expiry', time() + $ttl);
        update_user_meta($user->ID, 'ng_auth_otp_attempts', 0);

        $this->log_verification($user, 'sms', 'initiate', 'pending');

        return $otp;
    }

    /**
     * Проверка OTP-кода.
     *
     * Учитывает срок действия кода и лимит попыток.
     * При превышении лимита — блокирует пользователя.
     *
     * @param WP_User $user Пользователь.
     * @param string  $code OTP-код для проверки.
     * @return bool true, если код верен и не истёк.
     */
    public function verify_otp(WP_User $user, string $code): bool
    {
        $stored_hash = (string) get_user_meta($user->ID, 'ng_auth_otp', true);
        $expiry = (int) get_user_meta($user->ID, 'ng_auth_otp_expiry', true);

        if ('' === $stored_hash || 0 === $expiry) {
            return false;
        }

        if ($expiry < time()) {
            $this->clear_otp($user);
            return false;
        }

        $settings = get_option('ng_auth_settings', []);
        $max_attempts = (int) ($settings['otp_max_attempts'] ?? 5);

        $attempts = $this->increment_otp_attempts($user);

        if ($max_attempts < $attempts) {
            $this->mark_blocked($user);
            $this->clear_otp($user);
            $this->log_verification($user, 'sms', 'block', 'blocked');
            return false;
        }

        if (wp_check_password($code, $stored_hash)) {
            $this->clear_otp($user);
            return true;
        }

        return false;
    }

    /**
     * Инкремент счётчика попыток ввода OTP.
     *
     * @param WP_User $user Пользователь.
     * @return int Новое количество попыток.
     */
    public function increment_otp_attempts(WP_User $user): int
    {
        $attempts = (int) get_user_meta($user->ID, 'ng_auth_otp_attempts', true);
        $attempts++;
        update_user_meta($user->ID, 'ng_auth_otp_attempts', $attempts);
        return $attempts;
    }

    /**
     * Проверка возможности повторной отправки OTP.
     *
     * @param WP_User $user Пользователь.
     * @return bool true, если интервал между отправками соблюдён.
     */
    public function can_resend_otp(WP_User $user): bool
    {
        $settings = get_option('ng_auth_settings', []);
        $interval = (int) ($settings['otp_resend_interval'] ?? 60);

        $last_sent = (int) get_user_meta($user->ID, 'ng_auth_otp_expiry', true);
        $ttl = (int) ($settings['otp_ttl'] ?? 300);

        return (time() - ($last_sent - $ttl)) > $interval;
    }

    /**
     * Очистка OTP-данных пользователя.
     *
     * @param WP_User $user Пользователь.
     * @return void
     */
    public function clear_otp(WP_User $user): void
    {
        delete_user_meta($user->ID, 'ng_auth_otp');
        delete_user_meta($user->ID, 'ng_auth_otp_expiry');
        delete_user_meta($user->ID, 'ng_auth_otp_attempts');
    }

    /**
     * Получение количества попыток ввода OTP.
     *
     * @param WP_User $user Пользователь.
     * @return int Количество попыток.
     */
    public function get_otp_attempts(WP_User $user): int
    {
        return (int) get_user_meta($user->ID, 'ng_auth_otp_attempts', true);
    }

    /**
     * Получение оставшегося времени жизни OTP в секундах.
     *
     * @param WP_User $user Пользователь.
     * @return int Оставшееся время жизни OTP (не менее 0).
     */
    public function get_otp_ttl(WP_User $user): int
    {
        $ttl = (int) get_user_meta($user->ID, 'ng_auth_otp_expiry', true);
        return max(0, $ttl - time());
    }

    /**
     * Получение идентификатора провайдера, через которого пройдена верификация.
     *
     * @param WP_User $user Пользователь.
     * @return string Идентификатор провайдера.
     */
    public function get_provider(WP_User $user): string
    {
        return (string) get_user_meta($user->ID, 'ng_auth_provider', true);
    }

    /**
     * Получение даты и времени прохождения верификации.
     *
     * @param WP_User $user Пользователь.
     * @return string Дата в формате MySQL.
     */
    public function get_verified_at(WP_User $user): string
    {
        return (string) get_user_meta($user->ID, 'ng_auth_verified_at', true);
    }

    /**
     * Отметка времени отправки напоминания.
     *
     * @param WP_User $user Пользователь.
     * @return void
     */
    public function mark_reminder_sent(WP_User $user): void
    {
        update_user_meta($user->ID, 'ng_auth_reminder_sent_at', current_time('mysql'));
    }

    /**
     * Получение времени последнего отправленного напоминания.
     *
     * @param WP_User $user Пользователь.
     * @return string Дата в формате MySQL.
     */
    public function get_reminder_sent_at(WP_User $user): string
    {
        return (string) get_user_meta($user->ID, 'ng_auth_reminder_sent_at', true);
    }

    /**
     * Запись события в лог верификации.
     *
     * @param WP_User $user     Пользователь.
     * @param string  $provider Идентификатор провайдера.
     * @param string  $action   Действие (initiate, verify, block, fail).
     * @param string  $status   Статус (pending, verified, blocked, failed).
     * @return void
     */
    public function log_verification(WP_User $user, string $provider, string $action, string $status, string $message = ''): void
    {
        global $wpdb;
        $table = $wpdb->prefix . NG_AUTH_LOG_TABLE;

        $ip = '';
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        $wpdb->insert(
            $table,
            [
                'user_id' => $user->ID,
                'provider' => $provider,
                'action' => $action,
                'status' => $status,
                'ip_address' => $ip,
                'message' => $message,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Получение записей лога верификации пользователя.
     *
     * @param int $user_id ID пользователя.
     * @param int $limit   Максимальное количество записей (по умолчанию 20).
     * @return array[] Массив записей лога.
     */
    public function get_verification_log(int $user_id, int $limit = 20): array
    {
        global $wpdb;
        $table = $wpdb->prefix . NG_AUTH_LOG_TABLE;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
                $user_id,
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Получение списка неподтверждённых пользователей.
     *
     * @param int $batch_size Размер выборки (по умолчанию 50).
     * @param int $offset     Смещение выборки.
     * @return WP_User[] Массив объектов пользователей.
     */
    public function get_unverified_users(int $batch_size = 50, int $offset = 0): array
    {
        global $wpdb;

        $user_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT u.ID FROM {$wpdb->users} u
                LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'ng_auth_status'
                WHERE um.meta_value IS NULL OR um.meta_value != %s
                ORDER BY u.ID ASC
                LIMIT %d OFFSET %d",
                NG_AUTH_STATUS_VERIFIED,
                $batch_size,
                $offset
            )
        );

        return array_map('get_userdata', $user_ids);
    }

    /**
     * Полное удаление всех данных верификации пользователя.
     *
     * Очищает все мета-поля и записи в логе верификации.
     *
     * @param WP_User $user Пользователь.
     * @return void
     */
    public function erase_verification_data(WP_User $user): void
    {
        $keys = [
            'ng_auth_status',
            'ng_auth_provider',
            'ng_auth_verified_at',
            'ng_auth_phone',
            'ng_auth_otp',
            'ng_auth_otp_expiry',
            'ng_auth_otp_attempts',
            'ng_auth_esia_uid',
            'ng_auth_reminder_sent_at',
        ];

        foreach ($keys as $key) {
            delete_user_meta($user->ID, $key);
        }

        global $wpdb;
        $table = $wpdb->prefix . NG_AUTH_LOG_TABLE;
        $wpdb->delete($table, ['user_id' => $user->ID], ['%d']);
    }
}
