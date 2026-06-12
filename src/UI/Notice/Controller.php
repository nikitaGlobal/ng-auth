<?php

declare(strict_types=1);

/**
 * Notice Controller — управляет выводом уведомлений на фронтенде.
 *
 * Хранит сообщения в transient'ах (привязаны к сессии/токену),
 * выводит их через action-хуки на формах регистрации/входа.
 *
 * Поддерживает попап локаута через GET-параметр ng_auth=lockout.
 *
 * @package NG_Auth
 */

class NG_Auth_UI_Notice_Controller
{
    /**
     * Ключ transient'а для хранения notice.
     */
    private const TRANSIENT_KEY = 'ng_auth_notice_';

    /**
     * Регистрация хуков вывода notice и попапа.
     */
    public function __construct()
    {
        add_action('woocommerce_before_customer_login_form', [$this, 'render']);
        add_action('woocommerce_before_checkout_registration_form', [$this, 'render']);
        add_action('login_head', function () {
            add_filter('login_message', [$this, 'render']);
        });
        add_action('wp_footer', [$this, 'render_lockout_modal']);
        add_action('login_footer', [$this, 'render_lockout_modal'], 999);
        add_action('woocommerce_after_customer_login_form', [$this, 'render_lockout_modal']);
        add_action('woocommerce_before_customer_login_form', [$this, 'render_lockout_modal']);
        add_action('admin_footer', [$this, 'render_lockout_modal']);
        // Вывод до закрытия body — гарантирует видимость даже без wp_footer
        add_action('wp_body_open', [$this, 'render_lockout_modal']);
    }

    /**
     * Добавление notice в очередь.
     *
     * @param string $type    Тип notice: success, error, warning, info.
     * @param string $message Текст сообщения.
     * @return void
     */
    public static function add(string $type, string $message): void
    {
        $key = self::get_transient_key();
        $notices = get_transient($key) ?: [];
        $notices[] = ['type' => $type, 'message' => $message];
        set_transient($key, $notices, 60);
    }

    /**
     * Рендер всех накопленных notice и их очистка.
     *
     * @return string HTML notice.
     */
    public function render(): string
    {
        $key = self::get_transient_key();
        $notices = get_transient($key) ?: [];

        if (empty($notices)) {
            return '';
        }

        delete_transient($key);

        ob_start();
        include NG_AUTH_DIR . 'templates/notice.php';
        return (string) ob_get_clean();
    }

    /**
     * Рендер модального окна локаута.
     *
     * Проверяет GET-параметр ng_auth=lockout и выводит модальное окно
     * с таймером до разблокировки.
     *
     * @return void
     */
    public function render_lockout_modal(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }

        $action = isset($_GET['ng_auth']) ? sanitize_key(wp_unslash($_GET['ng_auth'])) : '';
        $until = isset($_GET['until']) ? (int) $_GET['until'] : 0;

        // Fallback: после AJAX-редиректа WooCommerce может делать канонический редирект,
        // теряя GET-параметры. Дублируем в transient.
        if ('' !== $action) {
            set_transient('ng_auth_lockout_modal', [
                'action' => $action,
                'until' => $until,
            ], 30);
        } else {
            $stored = get_transient('ng_auth_lockout_modal');
            if (is_array($stored) && !empty($stored['action'])) {
                $action = $stored['action'];
                $until = (int) ($stored['until'] ?? 0);
            }
        }

        if ('duplicate' === $action) {
            $message = __('Этот номер телефона уже привязан к другому пользователю. Если это ваш аккаунт — восстановите пароль или свяжитесь с администратором.', 'ng-auth');
            $remaining = 0;
        } elseif ('lockout' === $action && 0 < $until) {
            $remaining = max(0, $until - time());
            $message = '';
        } elseif ('lockout' === $action) {
            $message = '';
            $remaining = -1;
        } else {
            return;
        }

        $rendered = true;

        // Удаляем transient после показа (чтобы не показывать бесконечно)
        delete_transient('ng_auth_lockout_modal');

        $hours = floor(max(0, $remaining) / 3600);
        $minutes = floor((max(0, $remaining) % 3600) / 60);
        $seconds = max(0, $remaining) % 60;

        include NG_AUTH_DIR . 'templates/lockout-modal.php';
    }

    /**
     * Ключ transient'а на основе сессии или IP.
     */
    private static function get_transient_key(): string
    {
        $uid = get_current_user_id();
        if (0 < $uid) {
            return self::TRANSIENT_KEY . 'user_' . $uid;
        }

        $ip = '';
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return self::TRANSIENT_KEY . 'ip_' . md5($ip);
    }
}
