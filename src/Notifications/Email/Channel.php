<?php

/**
 * Канал отправки email-уведомлений NG Auth.
 *
 * Отправляет email-напоминания пользователям, не прошедшим верификацию,
 * с поддержкой плейсхолдеров в теме и теле письма.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_Notifications_Email_Channel
{
    /**
     * Отправка email-напоминания о необходимости верификации.
     *
     * Поддерживает плейсхолдеры: {site_name}, {user_name}, {verify_url}, {user_email}.
     * Если тема или тело не заданы в настройках — используются значения по умолчанию.
     *
     * @param WP_User              $user     Пользователь.
     * @param array<string, mixed> $settings Настройки плагина.
     * @return void
     */
    public function send_reminder(WP_User $user, array $settings): void
    {
        $subject = $settings['email_reminder_subject'] ?? '';
        $body = $settings['email_reminder_body'] ?? '';

        $site_name = get_bloginfo('name');
        $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);
        $verify_url = home_url("/{$slug}/");

        $replacements = [
            '{site_name}' => $site_name,
            '{user_name}' => $user->display_name,
            '{verify_url}' => $verify_url,
            '{user_email}' => $user->user_email,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subject);
        $body = str_replace(array_keys($replacements), array_values($replacements), $body);

        if ('' === $subject) {
            $subject = sprintf(
                __('[%s] Подтверждение аккаунта', 'ng-auth'),
                $site_name
            );
        }

        if ('' === $body) {
            $body = sprintf(
                __("Здравствуйте, %s!\n\nДля доступа к сайту %s необходимо подтвердить вашу личность.\n\nПерейдите по ссылке: %s\n\nЕсли у вас возникли вопросы, свяжитесь с администратором.", 'ng-auth'),
                $user->display_name,
                $site_name,
                $verify_url
            );
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($user->user_email, $subject, nl2br(esc_html($body)), $headers);
    }
}
