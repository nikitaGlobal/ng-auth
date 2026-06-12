<?php

/**
 * Канал отправки SMS-уведомлений NG Auth.
 *
 * Отправляет SMS-напоминания пользователям, не прошедшим верификацию,
 * через первый доступный активный SMS-провайдер.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_Notifications_SMS_Channel
{
    /**
     * Отправка SMS-напоминания о необходимости верификации.
     *
     * Получает номер телефона пользователя из мета-полей billing_phone/phone,
     * формирует сообщение по шаблону и отправляет через активный SMS-провайдер.
     *
     * @param WP_User              $user     Пользователь.
     * @param array<string, mixed> $settings Настройки плагина.
     * @return void
     */
    public function send_reminder(WP_User $user, array $settings): void
    {
        $phone = get_user_meta($user->ID, 'billing_phone', true);
        if ('' === $phone) {
            $phone = get_user_meta($user->ID, 'phone', true);
        }

        if ('' === $phone) {
            return;
        }

        $site_name = get_bloginfo('name');
        $template = $settings['sms_reminder_template']
            ?? __('Подтвердите ваш аккаунт на сайте {site_name}', 'ng-auth');

        $message = str_replace('{site_name}', $site_name, $template);

        $provider = $this->get_active_sms_provider();
        if ($provider instanceof NG_Auth_Providers_SMS) {
            $provider->send_sms($phone, $message);
        }
    }

    /**
     * Поиск первого доступного активного SMS-провайдера.
     *
     * @return NG_Auth_Providers_SMS|null Провайдер или null, если ни один не активен.
     */
    private function get_active_sms_provider(): ?NG_Auth_Providers_SMS
    {
        $plugin = NG_Auth_Core_Plugin::instance();
        $providers = $plugin->get_providers();

        foreach ($providers as $provider) {
            if ($provider instanceof NG_Auth_Providers_SMS && $provider->is_available()) {
                return $provider;
            }
        }

        return null;
    }
}
