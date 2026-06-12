<?php

declare(strict_types=1);

/**
 * Страница настроек плагина и подменю провайдеров.
 *
 * Регистрирует главное меню «NG Auth» с общей страницей настроек
 * и отдельными подстраницами для каждого провайдера верификации.
 *
 * @package NG_Auth
 */

class NG_Auth_Admin_Settings_Page
{
    /**
     * Зарегистрированные провайдеры верификации.
     *
     * @var NG_Auth_Contracts_Provider[]
     */
    private array $providers;

    /**
     * Слаг основной страницы настроек.
     *
     * @var string
     */
    private string $page_slug = 'ng-auth-settings';

    /**
     * @param NG_Auth_Contracts_Provider[] $providers Массив провайдеров.
     */
    public function __construct(array $providers)
    {
        $this->providers = $providers;

        add_action('admin_menu', [$this, 'add_menu_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('plugin_action_links_' . plugin_basename(NG_AUTH_FILE), [$this, 'add_action_links']);
    }

    /**
     * Регистрирует главное меню и подменю провайдеров.
     *
     * @return void
     */
    public function add_menu_pages(): void
    {
        add_menu_page(
            __('NG Авторизация', 'ng-auth'),
            __('NG Авторизация', 'ng-auth'),
            'manage_options',
            $this->page_slug,
            [$this, 'render'],
            'dashicons-shield',
            75
        );

        add_submenu_page(
            $this->page_slug,
            __('Общие настройки', 'ng-auth'),
            __('Общие настройки', 'ng-auth'),
            'manage_options',
            $this->page_slug,
            [$this, 'render']
        );

        add_submenu_page(
            $this->page_slug,
            __('Журнал верификации', 'ng-auth'),
            __('Журнал', 'ng-auth'),
            'manage_options',
            'ng-auth-logs',
            [new NG_Auth_Admin_Logs_Page(), 'render']
        );

        foreach ($this->providers as $provider) {
            $slug = 'ng-auth-provider-' . $provider->get_id();

            add_submenu_page(
                $this->page_slug,
                $provider->get_name(),
                $provider->get_name(),
                'manage_options',
                $slug,
                function () use ($provider, $slug): void {
                    $this->render_provider_page($provider, $slug);
                }
            );
        }
    }

    /**
     * Добавляет ссылку «Настройки» в строку действий плагина.
     *
     * @param string[] $links Существующие ссылки действий.
     * @return string[]
     */
    public function add_action_links(array $links): array
    {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=' . $this->page_slug)) . '">'
            . esc_html__('Настройки', 'ng-auth')
            . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Регистрирует настройки и секции через WordPress Settings API.
     *
     * @return void
     */
    public function register_settings(): void
    {
        register_setting('ng_auth_settings', 'ng_auth_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => NG_Auth_Core_Plugin::default_settings(),
        ]);

        add_settings_section(
            'ng_auth_general',
            __('Общие настройки', 'ng-auth'),
            '__return_empty_string',
            $this->page_slug
        );

        $this->add_field('enable_mandatory', __('Включить обязательное подтверждение', 'ng-auth'), 'checkbox');
        $this->add_field('selected_roles', __('Роли с обязательным подтверждением', 'ng-auth'), 'roles_checkboxes');
        $this->add_field('require_russian_ip', __('Требовать подтверждение только для IP из РФ', 'ng-auth'), 'checkbox');
        $this->add_field('http_logging', __('Вести журнал HTTP-запросов', 'ng-auth'), 'checkbox');
        $this->add_field('registration_notice', __('Текст уведомления на форме регистрации', 'ng-auth'), 'text');
        $this->add_field('geo_provider', __('Гео-IP провайдер', 'ng-auth'), 'select', [
            'nocgov' => 'НОЦ (geoip.noc.gov.ru)',
            'sweb' => 'Sweb (sweb.ru)',
        ]);
        $this->add_field('new_user_mode', __('Режим для новых пользователей', 'ng-auth'), 'select', [
            'soft' => __('Ограниченный доступ — пользователь создаётся, доступ до подтверждения ограничен', 'ng-auth'),
            'hard' => __('Без доступа — пользователь не создаётся до подтверждения', 'ng-auth'),
        ]);

        add_settings_section(
            'ng_auth_otp',
            __('OTP-настройки', 'ng-auth'),
            '__return_empty_string',
            $this->page_slug
        );

        $this->add_field('otp_length', __('Длина кода', 'ng-auth'), 'select', [
            4 => '4 ' . __('цифры', 'ng-auth'),
            6 => '6 ' . __('цифр', 'ng-auth'),
        ], 'ng_auth_otp');
        $this->add_field('otp_ttl', __('Время жизни кода (сек)', 'ng-auth'), 'number', null, 'ng_auth_otp');
        $this->add_field('otp_max_attempts', __('Максимальное число попыток', 'ng-auth'), 'number', null, 'ng_auth_otp');
        $this->add_field('otp_resend_interval', __('Интервал повторной отправки (сек)', 'ng-auth'), 'number', null, 'ng_auth_otp');

        add_settings_section(
            'ng_auth_templates',
            __('Шаблоны сообщений', 'ng-auth'),
            '__return_empty_string',
            $this->page_slug
        );

        $this->add_field('sms_otp_template', __('SMS OTP-шаблон', 'ng-auth'), 'text', null, 'ng_auth_templates');
        $this->add_field('sms_reminder_template', __('SMS напоминание', 'ng-auth'), 'text', null, 'ng_auth_templates');
        $this->add_field('email_reminder_subject', __('Тема Email-напоминания', 'ng-auth'), 'text', null, 'ng_auth_templates');
        $this->add_field('email_reminder_body', __('Тело Email-напоминания (HTML)', 'ng-auth'), 'textarea', null, 'ng_auth_templates');

        add_settings_section(
            'ng_auth_reminders',
            __('Рассылка напоминаний', 'ng-auth'),
            '__return_empty_string',
            $this->page_slug
        );

        $this->add_field('reminder_enabled', __('Включить рассылку', 'ng-auth'), 'checkbox', null, 'ng_auth_reminders');
        $this->add_field('reminder_delay_days', __('Задержка первого напоминания (дни)', 'ng-auth'), 'number', null, 'ng_auth_reminders');
        $this->add_field('reminder_batch_size', __('Размер батча', 'ng-auth'), 'number', null, 'ng_auth_reminders');
    }

    /**
     * Добавляет одно поле на страницу настроек.
     *
     * @param string      $key     Ключ опции.
     * @param string      $label   Метка поля.
     * @param string      $type    Тип поля (checkbox, select, text, number, textarea, password, roles).
     * @param mixed       $options Дополнительные опции для select.
     * @param string      $section Идентификатор секции.
     * @return void
     */
    private function add_field(string $key, string $label, string $type, $options = null, string $section = 'ng_auth_general'): void
    {
        add_settings_field(
            $key,
            $label,
            [$this, 'render_field'],
            $this->page_slug,
            $section,
            ['key' => $key, 'type' => $type, 'options' => $options]
        );
    }

    /**
     * Отрисовывает одно поле формы на основе его типа.
     *
     * @param array $args Аргументы поля: key, type, options.
     * @return void
     */
    public function render_field(array $args): void
    {
        $key = $args['key'] ?? '';
        $type = $args['type'] ?? 'text';
        $options = $args['options'] ?? null;

        $settings = get_option('ng_auth_settings', []);
        $value = $settings[$key] ?? '';
        $name = 'ng_auth_settings[' . esc_attr($key) . ']';
        $id = 'ng-auth-' . esc_attr($key);

        switch ($type) {
            case 'checkbox':
                echo '<label>';
                echo '<input type="checkbox" class="regular-checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"'
                    . checked(1, $value, false) . ' />';
                echo '</label>';
                break;

            case 'select':
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" class="regular-text">';
                if (is_array($options)) {
                    foreach ($options as $opt_value => $opt_label) {
                        echo '<option value="' . esc_attr((string) $opt_value) . '"'
                            . selected($value, $opt_value, false) . '>'
                            . esc_html($opt_label) . '</option>';
                    }
                }
                echo '</select>';
                break;

            case 'roles':
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '[]" multiple style="min-height:100px;min-width:200px;">';
                foreach (wp_roles()->roles as $role_key => $role_data) {
                    $selected = is_array($value) && in_array($role_key, $value, true);
                    echo '<option value="' . esc_attr($role_key) . '"'
                        . selected($selected, true, false) . '>'
                        . esc_html($role_data['name']) . '</option>';
                }
                echo '</select>';
                break;

            case 'roles_checkboxes':
                $all_roles = array_keys(wp_roles()->roles);
                $all_roles = array_filter($all_roles, function (string $role): bool {
                    return 'administrator' !== $role;
                });
                $all_checked = !is_array($value) || empty($value) || count(array_intersect($value, $all_roles)) === count($all_roles);
                echo '<fieldset>';
                echo '<p class="description" style="margin-bottom:8px;">'
                    . esc_html__('Администратор не требует подтверждения.', 'ng-auth')
                    . '</p>';
                foreach (wp_roles()->roles as $role_key => $role_data) {
                    if ('administrator' === $role_key) {
                        continue;
                    }
                    $checked = $all_checked || (is_array($value) && in_array($role_key, $value, true));
                    echo '<label style="display:block;margin-bottom:4px;">';
                    echo '<input type="checkbox" class="ng-auth-role-cb" name="' . esc_attr($name) . '[]" value="' . esc_attr($role_key) . '"'
                        . checked($checked, true, false) . ' /> ';
                    echo esc_html($role_data['name']);
                    echo '</label>';
                }
                echo '</fieldset>';
                break;

            case 'number':
                echo '<input type="number" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="'
                    . esc_attr((string) $value) . '" class="small-text" />';
                break;

            case 'textarea':
                echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="5" cols="50" class="large-text">'
                    . esc_textarea((string) $value) . '</textarea>';
                break;

            case 'password':
                echo '<input type="password" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="'
                    . esc_attr((string) $value) . '" class="regular-text" />';
                break;

            case 'readonly':
                echo '<input type="text" id="' . esc_attr($id) . '" value="'
                    . esc_attr((string) $value) . '" class="regular-text" readonly />';
                break;

            default:
                echo '<input type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="'
                    . esc_attr((string) $value) . '" class="regular-text" />';
                break;
        }
    }

    /**
     * Санитизирует входящие данные настроек перед сохранением.
     *
     * @param array $input Неочищенные данные формы.
     * @return array Очищенные настройки.
     */
    public function sanitize_settings(array $input): array
    {
        $sanitized = [];
        $defaults = NG_Auth_Core_Plugin::default_settings();

        $sanitized['enable_mandatory'] = !empty($input['enable_mandatory']);
        $sanitized['selected_roles'] = isset($input['selected_roles']) && is_array($input['selected_roles'])
            ? array_map('sanitize_key', $input['selected_roles'])
            : [];
        $all_roles = array_filter(array_keys(wp_roles()->roles), function (string $r): bool {
            return 'administrator' !== $r;
        });
        if (count($sanitized['selected_roles']) >= count($all_roles)) {
            $sanitized['selected_roles'] = [];
        }
        $sanitized['require_russian_ip'] = !empty($input['require_russian_ip']);
        $sanitized['http_logging'] = !empty($input['http_logging']);
        $sanitized['geo_provider'] = sanitize_key($input['geo_provider'] ?? 'nocgov');
        $sanitized['registration_notice'] = sanitize_text_field($input['registration_notice'] ?? $defaults['registration_notice']);
        $sanitized['new_user_mode'] = sanitize_key($input['new_user_mode'] ?? 'soft');
        $sanitized['otp_length'] = in_array((int) ($input['otp_length'] ?? 6), [4, 6], true)
            ? (int) $input['otp_length'] : 6;
        $sanitized['otp_ttl'] = max(60, (int) ($input['otp_ttl'] ?? 300));
        $sanitized['otp_max_attempts'] = max(1, (int) ($input['otp_max_attempts'] ?? 5));
        $sanitized['otp_resend_interval'] = max(10, (int) ($input['otp_resend_interval'] ?? 60));

        $sanitized['sms_otp_template'] = sanitize_text_field($input['sms_otp_template'] ?? $defaults['sms_otp_template']);
        $sanitized['sms_reminder_template'] = sanitize_text_field($input['sms_reminder_template'] ?? $defaults['sms_reminder_template']);
        $sanitized['email_reminder_subject'] = sanitize_text_field($input['email_reminder_subject'] ?? $defaults['email_reminder_subject']);
        $sanitized['email_reminder_body'] = wp_kses_post($input['email_reminder_body'] ?? '');

        $sanitized['reminder_enabled'] = !empty($input['reminder_enabled']);
        $sanitized['reminder_delay_days'] = max(1, (int) ($input['reminder_delay_days'] ?? 3));
        $sanitized['reminder_batch_size'] = max(10, min(200, (int) ($input['reminder_batch_size'] ?? 50)));

        foreach ($this->providers as $provider) {
            $prefix = 'provider_' . $provider->get_id() . '_';
            foreach ($input as $key => $value) {
                if (0 === strpos($key, $prefix)) {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            }
            $enabled_key = $prefix . 'enabled';
            $sanitized[$enabled_key] = !empty($input[$enabled_key]);
        }

        return $sanitized;
    }

    /**
     * Отрисовывает главную страницу общих настроек.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <?php $this->render_tabs('settings'); ?>
            <h1><?php echo esc_html__('NG Авторизация — Настройки', 'ng-auth'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ng_auth_settings');
                do_settings_sections($this->page_slug);
                submit_button();
                ?>
            </form>
            <hr />
            <p class="description">
                <?php esc_html_e('По вопросам настроек:', 'ng-auth'); ?>
                <a href="mailto:info@nikita.global">info@nikita.global</a>
                &mdash;
                <a href="https://t.me/nikitaglobalru" target="_blank" rel="noopener noreferrer">Telegram</a>
            </p>
        </div>
        <?php
    }

    /**
     * Отрисовывает таб-навигацию между настройками и провайдерами.
     *
     * @param string $active Идентификатор активной вкладки: 'settings' или ID провайдера.
     * @return void
     */
    private function render_tabs(string $active): void
    {
        $tabs = [
            [
                'id' => 'settings',
                'name' => __('Общие настройки', 'ng-auth'),
                'url' => admin_url('admin.php?page=' . $this->page_slug),
            ],
            [
                'id' => 'logs',
                'name' => __('Журнал', 'ng-auth'),
                'url' => admin_url('admin.php?page=ng-auth-logs'),
            ],
        ];

        foreach ($this->providers as $provider) {
            $tabs[] = [
                'id' => $provider->get_id(),
                'name' => $provider->get_name(),
                'url' => admin_url('admin.php?page=ng-auth-provider-' . $provider->get_id()),
            ];
        }

        ?>
        <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
            <?php foreach ($tabs as $tab): ?>
                <a href="<?php echo esc_url($tab['url']); ?>"
                   class="nav-tab <?php echo $tab['id'] === $active ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($tab['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Отрисовывает страницу настроек отдельного провайдера.
     *
     * Включает логотип, описание, инструкцию, таб-навигацию
     * и форму с полями, определёнными провайдером.
     *
     * @param NG_Auth_Contracts_Provider $provider  Текущий провайдер.
     * @param string                     $page_slug Слаг страницы в админке.
     * @return void
     */
    private function render_provider_page(NG_Auth_Contracts_Provider $provider, string $page_slug): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $provider_id = $provider->get_id();
        $prefix = 'provider_' . $provider_id . '_';
        $settings = get_option('ng_auth_settings', []);
        $fields = apply_filters('ng_auth_provider_admin_fields', $provider->init_form_fields(), $provider);

        if (isset($_POST['submit']) && check_admin_referer('ng_auth_provider_' . $provider_id, 'ng_auth_provider_nonce')) {
            $provider->process_admin_options();
            $settings = get_option('ng_auth_settings', []);
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Настройки сохранены.', 'ng-auth') . '</p></div>';
        }

        ?>
        <div class="wrap">
            <?php $this->render_tabs($provider->get_id()); ?>

            <div class="ng-auth-provider-header" style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <?php if ('' !== $provider->get_logo_url()): ?>
                    <img src="<?php echo esc_url($provider->get_logo_url()); ?>"
                         alt="" style="height:20px;" />
                <?php endif; ?>
                <h1 style="margin:0;"><?php echo esc_html($provider->get_name()); ?></h1>
            </div>

            <?php if ('' !== $provider->get_provider_url()): ?>
                <p class="description">
                    <a href="<?php echo esc_url($provider->get_provider_url()); ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($provider->get_provider_url()); ?>
                    </a>
                </p>
            <?php endif; ?>

            <p class="description"><?php echo esc_html($provider->get_description()); ?></p>

            <?php if ('' !== $provider->get_instructions()): ?>
                <div class="postbox" style="margin-bottom:16px;">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php esc_html_e('Инструкция по подключению', 'ng-auth'); ?></h2>
                    </div>
                    <div class="inside">
                        <p><?php echo nl2br(esc_html($provider->get_instructions())); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('ng_auth_provider_' . $provider_id, 'ng_auth_provider_nonce'); ?>
                <table class="form-table" role="presentation">
                    <?php foreach ($fields as $key => $field): ?>
                        <tr>
                            <th scope="row">
                                <label for="ng-auth-<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html($field['label']); ?>
                                </label>
                            </th>
                            <td>
                                <?php
                                $type = $field['type'] ?? 'text';
                                $value = $settings[$key] ?? ($field['default'] ?? '');
                                $input_name = esc_attr($key);
                                $input_id = 'ng-auth-' . esc_attr($key);
                                $required = !empty($field['required']) ? ' required' : '';

                                switch ($type) {
                                    case 'checkbox':
                                        echo '<label>';
                                        echo '<input type="checkbox" class="regular-checkbox" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="1"'
                                            . checked('1', $value, false) . ' />';
                                        echo '</label>';
                                        break;

                                    case 'select':
                                        echo '<select id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" class="regular-text">';
                                        if (isset($field['options']) && is_array($field['options'])) {
                                            foreach ($field['options'] as $opt_value => $opt_label) {
                                                echo '<option value="' . esc_attr((string) $opt_value) . '"'
                                                    . selected($value, $opt_value, false) . '>'
                                                    . esc_html($opt_label) . '</option>';
                                            }
                                        }
                                        echo '</select>';
                                        break;

                                    case 'number':
                                        echo '<input type="number" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="'
                                            . esc_attr((string) $value) . '" class="small-text" />';
                                        break;

                                    case 'password':
                                        echo '<input type="password" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="'
                                            . esc_attr((string) $value) . '" class="regular-text"' . $required . ' />';
                                        break;

                                    case 'readonly':
                                        echo '<input type="text" id="' . esc_attr($input_id) . '" value="'
                                            . esc_attr((string) $value) . '" class="regular-text" readonly />';
                                        echo '<button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)" style="margin-left:6px;">'
                                            . esc_html__('Копировать', 'ng-auth') . '</button>';
                                        break;

                                    default:
                                        echo '<input type="text" id="' . esc_attr($input_id) . '" name="' . esc_attr($input_name) . '" value="'
                                            . esc_attr((string) $value) . '" class="regular-text"' . $required . ' />';
                                        break;
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
