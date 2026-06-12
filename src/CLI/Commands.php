<?php

/**
 * CLI-команды NG Auth для WP-CLI.
 *
 * Предоставляет команды для управления верификацией пользователей
 * через интерфейс командной строки WordPress:
 * - mark-unverified — пометка пользователей без статуса.
 * - send-reminders — запуск рассылки напоминаний.
 * - status — просмотр статуса пользователя.
 * - verify — ручное подтверждение пользователя.
 * - stats — сводная статистика по верификации.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class NG_Auth_CLI_Commands
{
    /**
     * Пометка всех пользователей без статуса как unverified.
     *
     * ## EXAMPLES
     *
     *     wp ng-auth mark-unverified
     *
     * @subcommand mark-unverified
     *
     * @param array $args        Позиционные аргументы.
     * @param array $assoc_args  Ассоциативные аргументы.
     * @return void
     */
    public function mark_unverified(array $args, array $assoc_args): void
    {
        global $wpdb;

        $user_ids = $wpdb->get_col(
            "SELECT u.ID FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'ng_auth_status'
            WHERE um.meta_value IS NULL"
        );

        $count = count($user_ids);
        if (0 === $count) {
            WP_CLI::success(__('Все пользователи уже имеют статус.', 'ng-auth'));
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar(
            __('Пометка пользователей...', 'ng-auth'),
            $count
        );

        foreach ($user_ids as $user_id) {
            update_user_meta($user_id, 'ng_auth_status', NG_AUTH_STATUS_UNVERIFIED);
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success(
            sprintf(
                /* translators: %d: number of users */
                __('Помечено пользователей: %d', 'ng-auth'),
                $count
            )
        );
    }

    /**
     * Запуск рассылки напоминаний.
     *
     * ## EXAMPLES
     *
     *     wp ng-auth send-reminders
     *
     * @subcommand send-reminders
     *
     * @param array $args        Позиционные аргументы.
     * @param array $assoc_args  Ассоциативные аргументы.
     * @return void
     */
    public function send_reminders(array $args, array $assoc_args): void
    {
        $storage = new NG_Auth_Storage_User_Meta_Storage();
        $service = new NG_Auth_Notifications_Reminder_Service($storage);

        $service->process_batch();

        WP_CLI::success(__('Рассылка напоминаний выполнена.', 'ng-auth'));
    }

    /**
     * Показать статус пользователя.
     *
     * ## OPTIONS
     *
     * <user_id>
     * : ID пользователя.
     *
     * ## EXAMPLES
     *
     *     wp ng-auth status 42
     *
     * @subcommand status
     *
     * @param array $args        Позиционные аргументы (первый элемент — user_id).
     * @param array $assoc_args  Ассоциативные аргументы.
     * @return void
     */
    public function status(array $args, array $assoc_args): void
    {
        $user_id = (int) $args[0];
        $user = get_userdata($user_id);

        if (!$user instanceof WP_User) {
            WP_CLI::error(__('Пользователь не найден.', 'ng-auth'));
        }

        $storage = new NG_Auth_Storage_User_Meta_Storage();

        $status_labels = [
            NG_AUTH_STATUS_UNVERIFIED => __('Не подтверждён', 'ng-auth'),
            NG_AUTH_STATUS_PENDING => __('В процессе', 'ng-auth'),
            NG_AUTH_STATUS_VERIFIED => __('Подтверждён', 'ng-auth'),
            NG_AUTH_STATUS_BLOCKED => __('Заблокирован', 'ng-auth'),
        ];

        $status = $storage->get_status($user);
        $provider = $storage->get_provider($user);
        $verified_at = $storage->get_verified_at($user);

        WP_CLI::line(sprintf('ID:           %d', $user->ID));
        WP_CLI::line(sprintf('Email:        %s', $user->user_email));
        WP_CLI::line(sprintf(
            'Статус:       %s',
            $status_labels[$status] ?? $status
        ));
        WP_CLI::line(sprintf('Провайдер:    %s', $provider ?: '—'));
        WP_CLI::line(sprintf('Подтверждён:  %s', $verified_at ?: '—'));
    }

    /**
     * Ручное подтверждение пользователя администратором.
     *
     * ## OPTIONS
     *
     * <user_id>
     * : ID пользователя.
     *
     * [--provider=<provider>]
     * : Идентификатор провайдера (по умолчанию: manual).
     *
     * ## EXAMPLES
     *
     *     wp ng-auth verify 42
     *     wp ng-auth verify 42 --provider=smsaero
     *
     * @subcommand verify
     *
     * @param array $args        Позиционные аргументы (первый элемент — user_id).
     * @param array $assoc_args  Ассоциативные аргументы (--provider).
     * @return void
     */
    public function verify(array $args, array $assoc_args): void
    {
        $user_id = (int) $args[0];
        $user = get_userdata($user_id);

        if (!$user instanceof WP_User) {
            WP_CLI::error(__('Пользователь не найден.', 'ng-auth'));
        }

        $provider = $assoc_args['provider'] ?? 'manual';
        $storage = new NG_Auth_Storage_User_Meta_Storage();
        $storage->mark_verified($user, $provider);

        WP_CLI::success(
            sprintf(
                /* translators: %d: user ID */
                __('Пользователь %d подтверждён вручную.', 'ng-auth'),
                $user_id
            )
        );
    }

    /**
     * Показать сводку по верификации.
     *
     * ## EXAMPLES
     *
     *     wp ng-auth stats
     *
     * @subcommand stats
     *
     * @param array $args        Позиционные аргументы.
     * @param array $assoc_args  Ассоциативные аргументы.
     * @return void
     */
    public function stats(array $args, array $assoc_args): void
    {
        global $wpdb;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");

        $verified = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
                WHERE um.meta_key = 'ng_auth_status' AND um.meta_value = %s",
                NG_AUTH_STATUS_VERIFIED
            )
        );

        $unverified = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->users} u
                LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'ng_auth_status' AND um.meta_value = %s
                WHERE um.meta_value IS NULL
                OR (um.meta_key = 'ng_auth_status' AND um.meta_value != %s)",
                NG_AUTH_STATUS_VERIFIED,
                NG_AUTH_STATUS_VERIFIED
            )
        );

        $blocked = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta}
                WHERE meta_key = 'ng_auth_status' AND meta_value = %s",
                NG_AUTH_STATUS_BLOCKED
            )
        );

        WP_CLI::line(sprintf('Всего:        %d', $total));
        WP_CLI::line(sprintf('Подтверждено: %d', $verified));
        WP_CLI::line(sprintf('Не подтв.:    %d', $unverified));
        WP_CLI::line(sprintf('Заблокировано:%d', $blocked));
    }

    // ──────────────────────────────────────────────
    // Config-команды
    // ──────────────────────────────────────────────

    /**
     * Показать все настройки плагина.
     *
     * ## OPTIONS
     *
     * [<key>]
     * : Конкретный ключ настройки.
     *
     * [--format=<format>]
     * : Формат вывода (table, json, yaml). По умолчанию table.
     *
     * ## EXAMPLES
     *
     *     wp ng-auth config-get
     *     wp ng-auth config-get enable_mandatory
     *     wp ng-auth config-get --format=json
     *
     * @subcommand config-get
     *
     * @param array $args        Позиционные аргументы.
     * @param array $assoc_args  Ассоциативные аргументы.
     * @return void
     */
    public function config_get(array $args, array $assoc_args): void
    {
        $config = NG_Auth_Config::instance();
        $format = $assoc_args['format'] ?? 'table';

        if (!empty($args[0])) {
            $key = $args[0];
            $value = $config->get($key);
            $type = gettype($value);

            if ('json' === $format) {
                WP_CLI::line(wp_json_encode([$key => $value], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return;
            }

            WP_CLI::line(sprintf('%s (%s):', $key, $type));
            WP_CLI::line(is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        $all = $config->all();

        if ('json' === $format) {
            WP_CLI::line(wp_json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        $rows = [];
        foreach ($all as $key => $value) {
            $display = is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            $rows[] = [
                'Key' => $key,
                'Value' => mb_strlen($display) > 80 ? mb_substr($display, 0, 77) . '...' : $display,
            ];
        }

        WP_CLI\Utils\format_items('table', $rows, ['Key', 'Value']);
    }

    /**
     * Установить значение настройки.
     *
     * ## OPTIONS
     *
     * <key>
     * : Ключ настройки.
     *
     * <value>
     * : Значение (для булевых: 1/0 или yes/no, для массивов: JSON).
     *
     * ## EXAMPLES
     *
     *     wp ng-auth config-set enable_mandatory 1
     *     wp ng-auth config-set otp_length 4
     *     wp ng-auth config-set selected_roles '["customer","subscriber"]'
     *     wp ng-auth config-set provider_smsaero_enabled 1
     *
     * @subcommand config-set
     *
     * @param array $args        Позиционные аргументы.
     * @param array $assoc_args  Ассоциативные аргументы.
     * @return void
     */
    public function config_set(array $args, array $assoc_args): void
    {
        if (count($args) < 2) {
            WP_CLI::error(__('Укажите ключ и значение.', 'ng-auth'));
        }

        $key = $args[0];
        $raw_value = $args[1];

        // Пробуем декодировать JSON (для массивов и объектов).
        $decoded = json_decode($raw_value, true);
        $value = (JSON_ERROR_NONE === json_last_error()) ? $decoded : $raw_value;

        // Булевы значения
        if (is_string($value) && in_array(strtolower($value), ['true', 'yes', '1'], true)) {
            $value = true;
        } elseif (is_string($value) && in_array(strtolower($value), ['false', 'no', '0'], true)) {
            $value = false;
        }

        NG_Auth_Config::instance()->set($key, $value);
        WP_CLI::success(sprintf(__('Настройка %s обновлена.', 'ng-auth'), $key));
    }

    /**
     * Сбросить все настройки к значениям по умолчанию.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Подтверждение сброса.
     *
     * ## EXAMPLES
     *
     *     wp ng-auth config-reset --yes
     *
     * @subcommand config-reset
     *
     * @param array $args        Позиционные аргументы.
     * @param array $assoc_args  Ассоциативные аргументы.
     * @return void
     */
    public function config_reset(array $args, array $assoc_args): void
    {
        if (empty($assoc_args['yes'])) {
            WP_CLI::confirm(__('Вы уверены? Все настройки будут сброшены к значениям по умолчанию.', 'ng-auth'));
        }

        NG_Auth_Config::instance()->reset();
        WP_CLI::success(__('Настройки сброшены к значениям по умолчанию.', 'ng-auth'));
    }
}

/**
 * Регистрация CLI-команд в WP-CLI.
 *
 * @see NG_Auth_CLI_Commands
 */
WP_CLI::add_command('ng-auth', 'NG_Auth_CLI_Commands');
