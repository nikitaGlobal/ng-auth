<?php
/**
 * Template: Email reminder body (HTML).
 *
 * Override: copy to your-theme/ng-auth/email/reminder.php
 *
 * @var string $user_name
 * @var string $site_name
 * @var string $verify_url
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 32px;">
        <h2 style="margin: 0 0 16px; color: #111;">
            <?php echo esc_html__('Подтверждение личности', 'ng-auth'); ?>
        </h2>

        <p style="color: #374151; line-height: 1.6;">
            <?php
            printf(
                /* translators: %s: user display name */
                esc_html__('Здравствуйте, %s!', 'ng-auth'),
                esc_html($user_name)
            );
            ?>
        </p>

        <p style="color: #374151; line-height: 1.6;">
            <?php
            printf(
                /* translators: %s: site name */
                esc_html__('Для доступа к сайту %s необходимо подтвердить вашу личность.', 'ng-auth'),
                esc_html($site_name)
            );
            ?>
        </p>

        <p style="text-align: center; margin: 32px 0;">
            <a href="<?php echo esc_url($verify_url); ?>"
               style="display: inline-block; padding: 12px 32px; background: #2271b1; color: #fff;
                      text-decoration: none; border-radius: 4px; font-size: 16px;">
                <?php esc_html_e('Пройти подтверждение', 'ng-auth'); ?>
            </a>
        </p>

        <p style="color: #6b7280; font-size: 14px;">
            <?php esc_html_e('Если у вас возникли вопросы, свяжитесь с администратором.', 'ng-auth'); ?>
        </p>
    </div>
</body>
</html>
