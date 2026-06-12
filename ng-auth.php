<?php
/**
 * Plugin Name:       NG Авторизация
 * Description:       Обязательное подтверждение личности при регистрации и входе (SMS/ЕСИА). По вопросам настроек: info@nikita.global, Telegram @nikitaglobalru.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Nikita Menshutin
 * Author URI:        https://nikita.global
 * Plugin URI:        https://nikita.global
 * Update URI:        https://nikita.global
 * License:           GPL v2 or later
 * Text Domain:       ng-auth
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Текущая версия плагина.
 *
 * @var string
 */
define('NG_AUTH_VERSION', '1.0.0');

/**
 * Абсолютный путь к главному файлу плагина.
 *
 * @var string
 */
define('NG_AUTH_FILE', __FILE__);

/**
 * Абсолютный путь к директории плагина.
 *
 * @var string
 */
define('NG_AUTH_DIR', plugin_dir_path(__FILE__));

/**
 * URL к директории плагина.
 *
 * @var string
 */
define('NG_AUTH_URL', plugin_dir_url(__FILE__));

/**
 * Статус: пользователь не подтверждён.
 *
 * @var string
 */
define('NG_AUTH_STATUS_UNVERIFIED', 'unverified');

/**
 * Статус: подтверждение в процессе.
 *
 * @var string
 */
define('NG_AUTH_STATUS_PENDING', 'pending');

/**
 * Статус: пользователь подтверждён.
 *
 * @var string
 */
define('NG_AUTH_STATUS_VERIFIED', 'verified');

/**
 * Статус: пользователь заблокирован.
 *
 * @var string
 */
define('NG_AUTH_STATUS_BLOCKED', 'blocked');

/**
 * Slug страницы верификации.
 *
 * @var string
 */
define('NG_AUTH_VERIFY_SLUG', 'ng-auth-verify');

/**
 * Префикс настроек плагина.
 *
 * @var string
 */
define('NG_AUTH_OPTION_PREFIX', 'ng_auth_');

/**
 * Версия схемы БД плагина.
 *
 * @var string
 */
define('NG_AUTH_DB_VERSION', '1.0.0');

/**
 * Название таблицы лога верификации.
 *
 * @var string
 */
define('NG_AUTH_LOG_TABLE', 'ng_auth_verification_log');

require_once NG_AUTH_DIR . 'autoload.php';

/**
 * Генерация URL страницы верификации.
 *
 * При красивых ссылках: /ng-auth-verify/
 * При простых ссылках: /?ng_auth_verify=1
 *
 * @param array $extra_query Дополнительные query-параметры.
 * @return string Полный URL.
 */
function ng_auth_verify_url(array $extra_query = []): string
{
    return NG_Auth_Core_Plugin::instance()->get_verify_url($extra_query);
}

/**
 * Инициализация плагина на хуке plugins_loaded.
 *
 * @see NG_Auth_Core_Plugin::init()
 */
add_action('plugins_loaded', ['NG_Auth_Core_Plugin', 'init']);

/**
 * Регистрация хуков активации и деактивации плагина.
 *
 * @see NG_Auth_Core_Plugin::activate()
 * @see NG_Auth_Core_Plugin::deactivate()
 */
register_activation_hook(__FILE__, ['NG_Auth_Core_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['NG_Auth_Core_Plugin', 'deactivate']);

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Подключение CLI-команд при наличии WP-CLI.
     */
    add_action('plugins_loaded', function (): void {
        $cli_dir = NG_AUTH_DIR . 'src/CLI/';
        foreach (glob($cli_dir . '*.php') as $file) {
            require_once $file;
        }
    });
}
