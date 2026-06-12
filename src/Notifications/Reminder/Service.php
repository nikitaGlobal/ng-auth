<?php

/**
 * Сервис рассылки напоминаний о необходимости верификации.
 *
 * Через Cron-задачу рассылает email и SMS-уведомления пользователям,
 * которые не прошли обязательную верификацию в установленный срок.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_Notifications_Reminder_Service
{
    /**
     * Хранилище мета-данных пользователя.
     *
     * @var NG_Auth_Storage_User_Meta_Storage
     */
    private NG_Auth_Storage_User_Meta_Storage $storage;

    /**
     * Конструктор. Регистрирует Cron-интервал и событие.
     *
     * @param NG_Auth_Storage_User_Meta_Storage $storage Хранилище мета-данных.
     */
    public function __construct(NG_Auth_Storage_User_Meta_Storage $storage)
    {
        $this->storage = $storage;

        add_filter('cron_schedules', [$this, 'add_cron_intervals']);

        if (!wp_next_scheduled('ng_auth_reminder_cron')) {
            wp_schedule_event(time(), 'daily', 'ng_auth_reminder_cron');
        }
    }

    /**
     * Добавление кастомного Cron-интервала (6 часов).
     *
     * @param array<string, array{interval: int, display: string}> $schedules Зарегистрированные интервалы.
     * @return array<string, array{interval: int, display: string}> Обновлённые интервалы.
     */
    public function add_cron_intervals(array $schedules): array
    {
        $schedules['ng_auth_reminder_interval'] = [
            'interval' => HOUR_IN_SECONDS * 6,
            'display' => __('Каждые 6 часов (NG Auth)', 'ng-auth'),
        ];
        return $schedules;
    }

    /**
     * Обработка одной партии напоминаний.
     *
     * Получает список неподтверждённых пользователей и отправляет
     * напоминания тем, кому это необходимо согласно настройкам.
     *
     * @return void
     */
    public function process_batch(): void
    {
        $settings = get_option('ng_auth_settings', []);

        if (empty($settings['reminder_enabled'])) {
            return;
        }

        $batch_size = (int) ($settings['reminder_batch_size'] ?? 50);
        $delay_days = (int) ($settings['reminder_delay_days'] ?? 3);

        $users = $this->storage->get_unverified_users($batch_size);

        foreach ($users as $user) {
            if ($this->should_remind($user, $delay_days)) {
                $this->send_reminder($user);
                $this->storage->mark_reminder_sent($user);
            }
        }
    }

    /**
     * Проверка, нужно ли отправлять напоминание пользователю.
     *
     * Учитывает задержку после регистрации и интервал повторных напоминаний.
     *
     * @param WP_User $user       Пользователь.
     * @param int     $delay_days Задержка в днях после регистрации.
     * @return bool true, если напоминание нужно отправить.
     */
    private function should_remind(WP_User $user, int $delay_days): bool
    {
        $last_sent = $this->storage->get_reminder_sent_at($user);

        if ('' === $last_sent) {
            $registered = strtotime($user->user_registered);
            return (time() - $registered) >= ($delay_days * DAY_IN_SECONDS);
        }

        return (time() - strtotime($last_sent)) >= DAY_IN_SECONDS;
    }

    /**
     * Отправка напоминания пользователю.
     *
     * Отправляет email-напоминание и SMS (если хотя бы один SMS-провайдер активен).
     *
     * @param WP_User $user Пользователь.
     * @return void
     */
    private function send_reminder(WP_User $user): void
    {
        $settings = get_option('ng_auth_settings', []);

        $email = new NG_Auth_Notifications_Email_Channel();
        $email->send_reminder($user, $settings);

        $sms_enabled = false;
        foreach (['exolve', 'smsaero'] as $provider_id) {
            if (!empty($settings["provider_{$provider_id}_enabled"])) {
                $sms_enabled = true;
                break;
            }
        }

        if ($sms_enabled) {
            $sms = new NG_Auth_Notifications_SMS_Channel();
            $sms->send_reminder($user, $settings);
        }
    }
}
