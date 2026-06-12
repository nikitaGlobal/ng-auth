<?php
/**
 * Template: Provider selector page.
 *
 * @var NG_Auth_Contracts_Provider[] $providers
 * @var WP_User                      $user
 * @var string                       $base_url
 * @var string                       $token
 * @var string                       $cancel_url
 */

if (!defined('ABSPATH')) {
    exit;
}

$user_id = $user->ID;
$cancel_url = $cancel_url ?? wp_login_url();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e('Выберите способ подтверждения', 'ng-auth'); ?> — <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <style>
        .ng-auth-provider-card {
            display: block;
            padding: 20px;
            margin-bottom: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            text-decoration: none;
            color: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .ng-auth-provider-card:hover {
            border-color: #2271b1;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .ng-auth-provider-card h3 { margin: 0 0 4px; font-size: 18px; color: #2271b1; }
        .ng-auth-provider-card p { margin: 0; color: #666; font-size: 14px; }
    </style>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="ng-auth-container">
    <h2><?php esc_html_e('Подтверждение личности', 'ng-auth'); ?></h2>
    <p><?php esc_html_e('Выберите способ подтверждения:', 'ng-auth'); ?></p>

    <?php if (empty($providers)): ?>
        <p><?php esc_html_e('Нет доступных способов подтверждения. Обратитесь к администратору.', 'ng-auth'); ?></p>
    <?php else: ?>
        <?php foreach ($providers as $provider): ?>
            <a href="<?php echo esc_attr($base_url . '?provider=' . urlencode($provider->get_id()) . '&token=' . urlencode($token) . '&user_id=' . (int) $user_id); ?>" class="ng-auth-provider-card">
                <h3><?php echo esc_html($provider->get_name()); ?></h3>
                <p><?php echo esc_html($provider->get_description()); ?></p>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <button type="button" class="ng-auth-btn ng-auth-btn-secondary" id="ng-auth-cancel-btn" style="margin-top: 16px;">
        <?php esc_html_e('Отменить', 'ng-auth'); ?>
    </button>
</div>

<script>
(function() {
    'use strict';
    var cancelUrl = <?php echo wp_json_encode($cancel_url); ?>;
    var cancelBtn = document.getElementById('ng-auth-cancel-btn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            window.location.href = cancelUrl;
        });
    }
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
