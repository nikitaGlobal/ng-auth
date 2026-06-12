<?php

/**
 * Ядро плагина NG Auth — главный класс, управляющий жизненным циклом плагина.
 *
 * Реализует паттерн Singleton. Отвечает за начальную загрузку компонентов,
 * регистрацию провайдеров, хуков, Cron-задач, таблиц БД и скриптов.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_Core_Plugin
{
    /**
     * Экземпляр синглтона.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Хранилище мета-данных пользователя.
     *
     * @var NG_Auth_Storage_User_Meta_Storage
     */
    private NG_Auth_Storage_User_Meta_Storage $storage;

    /**
     * Сервис проверки обязательности верификации.
     *
     * @var NG_Auth_Core_Mandatory_Service
     */
    private NG_Auth_Core_Mandatory_Service $mandatory_service;

    /**
     * Обработчик регистрации пользователей.
     *
     * @var NG_Auth_Core_Registration_Handler
     */
    private NG_Auth_Core_Registration_Handler $registration_handler;

    /**
     * Обработчик аутентификации пользователей.
     *
     * @var NG_Auth_Core_Authentication_Handler
     */
    private NG_Auth_Core_Authentication_Handler $authentication_handler;

    /** @var NG_Auth_Contracts_Provider[] */
    private array $providers = [];

    /**
     * Инициализация плагина (точка входа).
     *
     * Вызывается из хука plugins_loaded.
     *
     * @return void
     */
    public static function init(): void
    {
        self::instance();
    }

    /**
     * Получение (или создание) экземпляра синглтона.
     *
     * @return self
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->bootstrap();
        }
        return self::$instance;
    }

    /**
     * Конструктор. Инициализирует хранилище и сервис обязательной верификации.
     */
    private function __construct()
    {
        $this->storage = new NG_Auth_Storage_User_Meta_Storage();
        $this->mandatory_service = new NG_Auth_Core_Mandatory_Service();
    }

    /**
     * Начальная загрузка: переводы, провайдеры, компоненты, хуки.
     *
     * @return void
     */
    private function bootstrap(): void
    {
        load_plugin_textdomain('ng-auth', false, dirname(plugin_basename(NG_AUTH_FILE)) . '/languages');

        $this->collect_providers();
        $this->init_components();
        $this->init_integrations();
        $this->register_hooks();
    }

    /**
     * Сбор зарегистрированных провайдеров верификации.
     *
     * Собирает встроенные провайдеры как имена классов, пропускает через фильтр
     * ng_auth_register_providers для расширения извне, затем инстанцирует каждый класс.
     *
     * @return void
     */
    private function collect_providers(): void
    {
        $class_names = [
            NG_Auth_Providers_Exolve::class,
            NG_Auth_Providers_SmsAero::class,
            NG_Auth_Providers_ESIA::class,
        ];

        $class_names = apply_filters('ng_auth_register_providers', $class_names);

        foreach ($class_names as $class_name) {
            if (is_string($class_name) && class_exists($class_name)) {
                $instance = new $class_name();
                if ($instance instanceof NG_Auth_Contracts_Provider) {
                    $this->providers[] = $instance;
                }
            }
        }
    }

    /**
     * Инициализация всех компонентов плагина.
     *
     * Создаёт обработчики регистрации, аутентификации, страницу настроек,
     * форму верификации, сервис напоминаний и WooCommerce-интеграции.
     *
     * @return void
     */
    private function init_components(): void
    {
        // Сначала создаём auth_handler — он нужен registration_handler и verification_form.
        $this->authentication_handler = new NG_Auth_Core_Authentication_Handler(
            $this->providers,
            $this->storage,
            $this->mandatory_service
        );

        $this->registration_handler = new NG_Auth_Core_Registration_Handler(
            $this->providers,
            $this->storage,
            $this->mandatory_service,
            $this->authentication_handler
        );

        if (is_admin()) {
            new NG_Auth_Admin_Settings_Page($this->providers);
        }

        new NG_Auth_UI_Verification_Form(
            $this->providers,
            $this->storage,
            $this->authentication_handler
        );

        new NG_Auth_UI_Notice_Controller();

        new NG_Auth_Notifications_Reminder_Service($this->storage);
    }

    /**
     * Инициализация активных интеграций.
     *
     * @return void
     */
    private function init_integrations(): void
    {
        $class_names = [
            NG_Auth_Integrations_WooCommerce::class,
        ];

        $class_names = apply_filters('ng_auth_register_integrations', $class_names);

        foreach ($class_names as $class_name) {
            if (is_string($class_name) && class_exists($class_name)) {
                $instance = new $class_name();
                if ($instance instanceof NG_Auth_Contracts_Integration && $instance->is_active()) {
                    $instance->init();
                }
            }
        }
    }

    /**
     * Регистрация основных хуков WordPress.
     *
     * @return void
     */
    private function register_hooks(): void
    {
        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_verify_page']);
        add_action('admin_bar_menu', [$this, 'admin_bar_status'], 100);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_action('ng_auth_reminder_cron', [$this, 'process_reminder_cron']);
    }

    /**
     * Регистрация rewrite-правил для страницы верификации.
     *
     * @return void
     */
    public function register_rewrite_rules(): void
    {
        $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);
        add_rewrite_rule('^' . $slug . '/?$', 'index.php?ng_auth_verify=1', 'top');
    }

    /**
     * Генерация URL страницы верификации.
     *
     * При красивых ссылках: /ng-auth-verify/
     * При простых ссылках: /?ng_auth_verify=1
     *
     * @param array $extra_query Дополнительные query-параметры.
     * @return string Полный URL.
     */
    public function get_verify_url(array $extra_query = []): string
    {
        $structure = get_option('permalink_structure');
        $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);

        if ('' === $structure) {
            // Простые ссылки
            $base = home_url('/');
            $extra_query['ng_auth_verify'] = '1';
            return add_query_arg($extra_query, $base);
        }

        // Красивые ссылки
        $url = home_url("/{$slug}/");
        if (!empty($extra_query)) {
            $url = add_query_arg($extra_query, $url);
        }
        return $url;
    }

    /**
     * Добавление кастомных query-переменных.
     *
     * @param string[] $vars Массив зарегистрированных query-переменных.
     * @return string[] Обновлённый массив query-переменных.
     */
    public function add_query_vars(array $vars): array
    {
        $vars[] = 'ng_auth_verify';
        $vars[] = 'ng_auth_action';
        return $vars;
    }

    /**
     * Обработка запроса к странице верификации.
     *
     * Срабатывает:
     * - При красивых ссылках: /ng-auth-verify/ → query_var ng_auth_verify=1
     * - При простых ссылках: ?ng_auth_verify=1 → проверка $_GET
     *
     * @return void
     */
    public function handle_verify_page(): void
    {
        $is_verify = (int) get_query_var('ng_auth_verify') === 1
            || (isset($_GET['ng_auth_verify']) && '1' === $_GET['ng_auth_verify']);

        if ($is_verify) {
            do_action('ng_auth_verify_page');
            exit;
        }
    }

    /**
     * Отображение статуса верификации в админ-баре.
     *
     * Показывает количество неподтверждённых пользователей.
     *
     * @param WP_Admin_Bar $admin_bar Объект админ-бара WordPress.
     * @return void
     */
    public function admin_bar_status(WP_Admin_Bar $admin_bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $total = count_users();
        $total_users = (int) ($total['total_users'] ?? 0);

        global $wpdb;
        $unverified = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != %s",
                'ng_auth_status',
                NG_AUTH_STATUS_VERIFIED
            )
        );

        $admin_bar->add_node([
            'id' => 'ng-auth-status',
            'title' => sprintf(
                __('Проверка: %d/%d', 'ng-auth'),
                $unverified,
                $total_users
            ),
            'href' => admin_url('admin.php?page=ng-auth-settings'),
        ]);
    }

    /**
     * Обработчик Cron-задачи рассылки напоминаний.
     *
     * @return void
     */
    public function process_reminder_cron(): void
    {
        (new NG_Auth_Notifications_Reminder_Service($this->storage))->process_batch();
    }

    /**
     * Подключение фронтенд-ассетов на странице верификации.
     *
     * @return void
     */
    public function enqueue_frontend_assets(): void
    {
        if ((int) get_query_var('ng_auth_verify') === 1) {
            wp_enqueue_style(
                'ng-auth-frontend',
                NG_AUTH_URL . 'assets/css/ng-auth.css',
                [],
                NG_AUTH_VERSION
            );
            wp_enqueue_script(
                'ng-auth-frontend',
                NG_AUTH_URL . 'assets/js/ng-auth.js',
                ['jquery'],
                NG_AUTH_VERSION,
                true
            );
        }
    }

    /**
     * Подключение админ-ассетов на странице настроек плагина.
     *
     * @return void
     */
    public function enqueue_admin_assets(): void
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'toplevel_page_ng-auth-settings') {
            wp_enqueue_style(
                'ng-auth-admin',
                NG_AUTH_URL . 'assets/css/ng-auth.css',
                [],
                NG_AUTH_VERSION
            );
        }
    }

    /**
     * Действия при активации плагина.
     *
     * Создаёт таблицу логов, регистрирует Cron-задачу,
     * записывает настройки по умолчанию и сбрасывает rewrite-правила.
     *
     * @return void
     */
    public static function activate(): void
    {
        self::create_log_table();
        self::maybe_register_cron();

        if (!get_option('ng_auth_settings')) {
            update_option('ng_auth_settings', self::default_settings());
        }

        flush_rewrite_rules();
    }

    /**
     * Действия при деактивации плагина.
     *
     * Очищает Cron-задачу напоминаний и сбрасывает rewrite-правила.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('ng_auth_reminder_cron');
        flush_rewrite_rules();
    }

    /**
     * Создание таблицы логов верификации.
     *
     * Использует dbDelta() для безопасного создания/обновления схемы.
     *
     * @return void
     */
    private static function create_log_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . NG_AUTH_LOG_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(50) NOT NULL DEFAULT '',
            action VARCHAR(20) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT '',
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            message TEXT NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY provider (provider),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('ng_auth_db_version', NG_AUTH_DB_VERSION);
    }

    /**
     * Регистрация Cron-события напоминаний, если оно ещё не запланировано.
     *
     * @return void
     */
    private static function maybe_register_cron(): void
    {
        if (!wp_next_scheduled('ng_auth_reminder_cron')) {
            wp_schedule_event(time(), 'daily', 'ng_auth_reminder_cron');
        }
    }

    /**
     * Настройки плагина по умолчанию.
     *
     * @return array<string, mixed> Ассоциативный массив настроек.
     */
    public static function default_settings(): array
    {
        return [
            'enable_mandatory' => true,
            'selected_roles' => [],
            'new_user_mode' => 'soft',
            'geo_provider' => 'nocgov',
            'http_logging' => false,
            'registration_notice' => __('После регистрации потребуется подтверждение личности.', 'ng-auth'),
            'otp_length' => 6,
            'otp_ttl' => 300,
            'otp_max_attempts' => 5,
            'otp_resend_interval' => 60,
            'reminder_enabled' => false,
            'reminder_delay_days' => 3,
            'reminder_interval' => 'daily',
            'reminder_batch_size' => 50,
            'sms_otp_template' => __('Ваш код подтверждения: {code}', 'ng-auth'),
            'sms_reminder_template' => __('Подтвердите ваш аккаунт на сайте {site_name}', 'ng-auth'),
            'email_reminder_subject' => __('Подтверждение аккаунта на {site_name}', 'ng-auth'),
            'email_reminder_body' => '',
        ];
    }

    /**
     * Проверка активности WooCommerce.
    /**
     * Получение списка зарегистрированных провайдеров.
     *
     * @return NG_Auth_Contracts_Provider[] Массив провайдеров верификации.
     */
    public function get_providers(): array
    {
        return $this->providers;
    }

    /**
     * Получение экземпляра хранилища мета-данных.
     *
     * @return NG_Auth_Storage_User_Meta_Storage
     */
    public function get_storage(): NG_Auth_Storage_User_Meta_Storage
    {
        return $this->storage;
    }

    /**
     * Получение сервиса обязательной верификации.
     *
     * @return NG_Auth_Core_Mandatory_Service
     */
    public function get_mandatory_service(): NG_Auth_Core_Mandatory_Service
    {
        return $this->mandatory_service;
    }

    /**
     * Получение обработчика аутентификации.
     *
     * @return NG_Auth_Core_Authentication_Handler
     */
    public function get_authentication_handler(): NG_Auth_Core_Authentication_Handler
    {
        return $this->authentication_handler;
    }

}
