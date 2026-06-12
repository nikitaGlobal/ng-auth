<?php

declare(strict_types=1);

/**
 * Страница логов верификации в админке.
 *
 * @package NG_Auth
 */

class NG_Auth_Admin_Logs_Page
{
    private string $page_slug = 'ng-auth-logs';

    public function __construct()
    {
    }

    public function add_page(): void
    {
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . NG_AUTH_LOG_TABLE;
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $offset = ($page - 1) * $per_page;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $total_pages = (int) ceil($total / $per_page);

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $status_labels = [
            'verified' => __('Подтверждён', 'ng-auth'),
            'pending' => __('В процессе', 'ng-auth'),
            'failed' => __('Ошибка', 'ng-auth'),
            'blocked' => __('Заблокирован', 'ng-auth'),
        ];

        $action_labels = [
            'initiate' => __('Запуск', 'ng-auth'),
            'verify' => __('Проверка', 'ng-auth'),
            'fail' => __('Ошибка', 'ng-auth'),
            'block' => __('Блокировка', 'ng-auth'),
            'http_log' => __('HTTP', 'ng-auth'),
            'test_otp' => __('Тест OTP', 'ng-auth'),
        ];

        ?>
        <div class="wrap">
            <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ng-auth-settings')); ?>"
                   class="nav-tab"><?php esc_html_e('Общие настройки', 'ng-auth'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ng-auth-logs')); ?>"
                   class="nav-tab nav-tab-active"><?php esc_html_e('Журнал', 'ng-auth'); ?></a>
                <?php foreach (NG_Auth_Core_Plugin::instance()->get_providers() as $p): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ng-auth-provider-' . $p->get_id())); ?>"
                       class="nav-tab"><?php echo esc_html($p->get_name()); ?></a>
                <?php endforeach; ?>
            </nav>
            <h1><?php esc_html_e('Журнал верификации', 'ng-auth'); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?php esc_html_e('Пользователь', 'ng-auth'); ?></th>
                        <th><?php esc_html_e('Провайдер', 'ng-auth'); ?></th>
                        <th><?php esc_html_e('Действие', 'ng-auth'); ?></th>
                        <th><?php esc_html_e('Результат', 'ng-auth'); ?></th>
                        <th>IP</th>
                        <th><?php esc_html_e('Дата', 'ng-auth'); ?></th>
                        <th><?php esc_html_e('Сообщение', 'ng-auth'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e('Записи отсутствуют.', 'ng-auth'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo (int) $log['id']; ?></td>
                                <td>
                                    <?php
                                    $user = get_userdata((int) $log['user_id']);
                                    echo $user instanceof WP_User
                                        ? esc_html($user->user_login)
                                        : '#' . (int) $log['user_id'];
                                    ?>
                                </td>
                                <td><?php echo esc_html($log['provider']); ?></td>
                                <td><?php echo esc_html($action_labels[$log['action']] ?? $log['action']); ?></td>
                                <td><?php echo esc_html($status_labels[$log['status']] ?? $log['status']); ?></td>
                                <td><?php echo esc_html($log['ip_address']); ?></td>
                                <td><?php echo esc_html($log['created_at']); ?></td>
                                <td><?php echo esc_html($log['message'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php echo esc_html(sprintf(__('%d записей', 'ng-auth'), $total)); ?>
                        </span>
                        <span class="pagination-links">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <?php if ($i === $page): ?>
                                    <span class="tablenav-pages-navspan" aria-current="page"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a class="page-numbers" href="<?php echo esc_url(add_query_arg('paged', $i)); ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
