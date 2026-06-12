<?php
/**
 * Template: notice display.
 *
 * @var array $notices Array of ['type' => string, 'message' => string].
 */

if (!defined('ABSPATH')) {
    exit;
}

$class_map = [
    'success' => 'woocommerce-message',
    'error'   => 'woocommerce-error',
    'warning' => 'woocommerce-info',
    'info'    => 'woocommerce-info',
];

foreach ($notices as $notice):
    $css_class = $class_map[$notice['type']] ?? 'woocommerce-info';
    ?>
    <div class="<?php echo esc_attr($css_class); ?>" role="alert">
        <?php echo esc_html($notice['message']); ?>
    </div>
    <?php
endforeach;
