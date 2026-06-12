<?php

/**
 * Таблица пользователей с колонками статуса верификации в админке NG Auth.
 *
 * Расширяет WP_List_Table для отображения пользователей с информацией
 * о статусе верификации, провайдере, дате подтверждения и телефоне.
 * Поддерживает сортировку, фильтрацию по статусу и массовые действия.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NG_Auth_Admin_Users_List_Table extends WP_List_Table
{
    /**
     * Конструктор таблицы.
     *
     * Задаёт singular/plural метки для интерфейса.
     */
    public function __construct()
    {
        parent::__construct([
            'singular' => __('пользователь', 'ng-auth'),
            'plural' => __('пользователи', 'ng-auth'),
            'ajax' => false,
        ]);
    }

    /**
     * Определение колонок таблицы.
     *
     * @return array<string, string> Ассоциативный массив колонок.
     */
    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox" />',
            'username' => __('Имя пользователя', 'ng-auth'),
            'email' => __('Email', 'ng-auth'),
            'status' => __('Статус верификации', 'ng-auth'),
            'provider' => __('Провайдер', 'ng-auth'),
            'verified_at' => __('Дата подтверждения', 'ng-auth'),
            'phone' => __('Телефон', 'ng-auth'),
        ];
    }

    /**
     * Определение сортируемых колонок.
     *
     * @return array<string, array{string, bool}> Сортируемые колонки.
     */
    public function get_sortable_columns(): array
    {
        return [
            'username' => ['user_login', false],
            'email' => ['user_email', false],
            'status' => ['ng_auth_status', false],
            'verified_at' => ['ng_auth_verified_at', false],
        ];
    }

    /**
     * Подготовка элементов таблицы к отображению.
     *
     * Выполняет WP_User_Query с учётом сортировки, пагинации,
     * поиска и фильтрации по статусу.
     *
     * @return void
     */
    public function prepare_items(): void
    {
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'user_login';
        $order = isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'ASC';

        $status_filter = isset($_GET['ng_auth_status']) ? sanitize_key(wp_unslash($_GET['ng_auth_status'])) : '';

        $args = [
            'number' => $per_page,
            'offset' => ($current_page - 1) * $per_page,
            'orderby' => $orderby,
            'order' => $order,
        ];

        if ('' !== $status_filter) {
            $args['meta_key'] = 'ng_auth_status';
            $args['meta_value'] = $status_filter;
        }

        if (isset($_GET['s']) && '' !== $_GET['s']) {
            $args['search'] = '*' . sanitize_text_field(wp_unslash($_GET['s'])) . '*';
        }

        $user_query = new WP_User_Query($args);
        $this->items = $user_query->get_results();

        $total = $user_query->get_total();

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    /**
     * Отрисовка чекбокса для массовых действий.
     *
     * @param WP_User|object $item Объект строки таблицы.
     * @return string HTML чекбокса.
     */
    public function column_cb($item): string
    {
        if ($item instanceof WP_User) {
            return sprintf(
                '<input type="checkbox" name="users[]" value="%d" />',
                $item->ID
            );
        }
        return '';
    }

    /**
     * Отрисовка колонки с именем пользователя и действиями.
     *
     * @param WP_User|object $item Объект строки таблицы.
     * @return string HTML содержимого ячейки.
     */
    public function column_username($item): string
    {
        if (!$item instanceof WP_User) {
            return '';
        }

        $edit_link = get_edit_user_link($item->ID);
        $actions = [
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($edit_link),
                __('Изменить', 'ng-auth')
            ),
            'verify' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(wp_nonce_url(
                    admin_url('admin.php?page=ng-auth-settings&action=verify&user_id=' . $item->ID),
                    'ng_auth_verify_user_' . $item->ID
                )),
                __('Подтвердить', 'ng-auth')
            ),
        ];

        return sprintf(
            '<strong><a href="%1$s">%2$s</a></strong> %3$s',
            esc_url($edit_link),
            esc_html($item->user_login),
            $this->row_actions($actions)
        );
    }

    /**
     * Отрисовка колонки email.
     *
     * @param WP_User|object $item Объект строки таблицы.
     * @return string HTML содержимого ячейки.
     */
    public function column_email($item): string
    {
        if (!$item instanceof WP_User) {
            return '';
        }
        return esc_html($item->user_email);
    }

    /**
     * Отрисовка колонки статуса верификации с цветовой индикацией.
     *
     * @param WP_User|object $item Объект строки таблицы.
     * @return string HTML с цветной меткой статуса.
     */
    public function column_status($item): string
    {
        if (!$item instanceof WP_User) {
            return '';
        }

        $storage = new NG_Auth_Storage_User_Meta_Storage();
        $status = $storage->get_status($item);

        $labels = [
            NG_AUTH_STATUS_UNVERIFIED => '<span style="color:#eab308;">' . esc_html__('Не подтверждён', 'ng-auth') . '</span>',
            NG_AUTH_STATUS_PENDING => '<span style="color:#3b82f6;">' . esc_html__('В процессе', 'ng-auth') . '</span>',
            NG_AUTH_STATUS_VERIFIED => '<span style="color:#16a34a;">' . esc_html__('Подтверждён', 'ng-auth') . '</span>',
            NG_AUTH_STATUS_BLOCKED => '<span style="color:#dc2626;">' . esc_html__('Заблокирован', 'ng-auth') . '</span>',
        ];

        return $labels[$status] ?? esc_html($status);
    }

    /**
     * Отрисовка колонки провайдера верификации.
     *
     * @param WP_User|object $item Объект строки таблицы.
     * @return string Название провайдера или прочерк.
     */
    public function column_provider($item): string
    {
        if (!$item instanceof WP_User) {
            return '';
        }

        $storage = new NG_Auth_Storage_User_Meta_Storage();
        $provider = $storage->get_provider($item);

        return $provider !== '' ? esc_html($provider) : '—';
    }

    /**
     * Отрисовка колонки даты подтверждения.
     *
     * @param WP_User|object $item Объект строки таблицы.
     * @return string Дата подтверждения или прочерк.
     */
    public function column_verified_at($item): string
    {
        if (!$item instanceof WP_User) {
            return '';
        }

        $storage = new NG_Auth_Storage_User_Meta_Storage();
        $verified_at = $storage->get_verified_at($item);

        return $verified_at !== '' ? esc_html($verified_at) : '—';
    }

    /**
     * Отрисовка колонки телефона (хеш).
     *
     * Отображает первые 12 символов хеша номера телефона.
     *
     * @param WP_User|object $item Объект строки таблицы.
     * @return string Частичный хеш телефона или прочерк.
     */
    public function column_phone($item): string
    {
        if (!$item instanceof WP_User) {
            return '';
        }

        $storage = new NG_Auth_Storage_User_Meta_Storage();
        $hash = $storage->get_phone_hash($item);

        return $hash !== '' ? esc_html(substr($hash, 0, 12) . '...') : '—';
    }

    /**
     * Дополнительные элементы навигации таблицы (фильтр по статусу).
     *
     * @param string $which Позиция ('top' или 'bottom').
     * @return void
     */
    protected function extra_tablenav($which): void
    {
        if ('top' !== $which) {
            return;
        }

        $current_status = isset($_GET['ng_auth_status']) ? sanitize_key(wp_unslash($_GET['ng_auth_status'])) : '';
        $statuses = [
            '' => __('Все статусы', 'ng-auth'),
            NG_AUTH_STATUS_UNVERIFIED => __('Не подтверждён', 'ng-auth'),
            NG_AUTH_STATUS_PENDING => __('В процессе', 'ng-auth'),
            NG_AUTH_STATUS_VERIFIED => __('Подтверждён', 'ng-auth'),
            NG_AUTH_STATUS_BLOCKED => __('Заблокирован', 'ng-auth'),
        ];

        echo '<div class="alignleft actions">';
        echo '<select name="ng_auth_status">';
        foreach ($statuses as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($current_status, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
        submit_button(__('Фильтр', 'ng-auth'), 'action', '', false);
        echo '</div>';
    }

    /**
     * Массовые действия с пользователями.
     *
     * @return array<string, string> Доступные массовые действия.
     */
    public function get_bulk_actions(): array
    {
        return [
            'mark_unverified' => __('Пометить как не подтверждённых', 'ng-auth'),
            'mark_verified' => __('Подтвердить', 'ng-auth'),
            'mark_blocked' => __('Заблокировать', 'ng-auth'),
        ];
    }
}
