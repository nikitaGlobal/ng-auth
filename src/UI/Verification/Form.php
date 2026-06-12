<?php

/**
 * Форма верификации на фронтенде NG Auth.
 *
 * Отвечает за отрисовку страницы подтверждения личности, обработку
 * AJAX-запросов (отправка OTP, проверка кода) и OAuth-колбэков
 * от внешних провайдеров (например, ЕСИА).
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_UI_Verification_Form
{
    /** @var NG_Auth_Contracts_Provider[] */
    private array $providers;

    /**
     * Хранилище мета-данных пользователя.
     *
     * @var NG_Auth_Storage_User_Meta_Storage
     */
    private NG_Auth_Storage_User_Meta_Storage $storage;

    /**
     * Обработчик аутентификации (для обновления токена).
     *
     * @var NG_Auth_Core_Authentication_Handler|null
     */
    private ?NG_Auth_Core_Authentication_Handler $auth_handler;

    /**
     * Конструктор. Регистрирует хуки отрисовки и AJAX-обработчиков.
     *
     * @param NG_Auth_Contracts_Provider[]             $providers    Массив провайдеров верификации.
     * @param NG_Auth_Storage_User_Meta_Storage        $storage      Хранилище мета-данных.
     * @param NG_Auth_Core_Authentication_Handler|null $auth_handler Обработчик аутентификации.
     */
    public function __construct(
        array $providers,
        NG_Auth_Storage_User_Meta_Storage $storage,
        ?NG_Auth_Core_Authentication_Handler $auth_handler = null
    ) {
        $this->providers = $providers;
        $this->storage = $storage;
        $this->auth_handler = $auth_handler;

        add_action('ng_auth_verify_page', [$this, 'render']);
        add_action('wp_ajax_ng_auth_send_otp', [$this, 'handle_send_otp']);
        add_action('wp_ajax_nopriv_ng_auth_send_otp', [$this, 'handle_send_otp']);
        add_action('wp_ajax_ng_auth_verify_code', [$this, 'handle_verify_code']);
        add_action('wp_ajax_nopriv_ng_auth_verify_code', [$this, 'handle_verify_code']);

        $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);
        add_action('wp_ajax_nopriv_ng_auth_callback_' . $slug, [$this, 'handle_esia_callback']);
        add_action('wp_ajax_ng_auth_callback_' . $slug, [$this, 'handle_esia_callback']);
    }

    /**
     * Главный метод отрисовки страницы верификации.
     *
     * Определяет пользователя, текущего провайдера и отображает
     * соответствующую форму: выбор провайдера, SMS-форма или кнопка ЕСИА.
     *
     * @return void
     */
    public function render(): void
    {
        $user = $this->get_current_verification_user();

        if (!$user instanceof WP_User) {
            NG_Auth_Log_Logger::warning('Verification page: user not found', [
                'user_id_param' => isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0,
                'has_token' => !empty($_GET['token']),
                'is_logged_in' => is_user_logged_in(),
                'auth_handler' => $this->auth_handler ? 'set' : 'null',
            ]);
            wp_die(esc_html__('Пользователь не найден.', 'ng-auth'), 404);
        }

        if ($this->storage->is_verified($user)) {
            wp_safe_redirect(home_url());
            exit;
        }

        // Проверка локаута: email или телефон заблокированы.
        $lockout = new NG_Auth_Core_Lockout_Service();
        $phone_raw = get_user_meta($user->ID, 'ng_auth_phone_raw', true);

        $remaining = 0;
        if ($lockout->is_email_locked($user->user_email)) {
            $remaining = max($remaining, $lockout->email_lock_remaining($user->user_email));
        }
        if ('' !== $phone_raw && $lockout->is_phone_locked($phone_raw)) {
            $remaining = max($remaining, $lockout->phone_lock_remaining($phone_raw));
        }

        if (0 < $remaining) {
            NG_Auth_UI_Notice_Controller::add('error',
                __('Ваш email или номер телефона заблокированы из-за превышения числа попыток. Попробуйте позже.', 'ng-auth')
            );
            $return_url = $this->auth_handler ? $this->auth_handler->get_return_url($user) : wp_login_url();
            $return_url = add_query_arg([
                'ng_auth' => 'lockout',
                'until' => time() + $remaining,
            ], $return_url);
            wp_safe_redirect($return_url);
            exit;
        }

        $available = $this->get_available_providers();

        if (empty($available)) {
            $this->storage->mark_verified($user, 'auto');
            NG_Auth_Log_Logger::info('No providers available, auto-verifying user', [
                'user_id' => $user->ID,
            ]);
            wp_set_auth_cookie($user->ID);
            wp_safe_redirect(home_url());
            exit;
        }

        $provider_id = isset($_GET['provider']) ? sanitize_key(wp_unslash($_GET['provider'])) : '';
        $provider = $this->find_provider($provider_id);

        if (!$provider && 1 === count($available)) {
            $single = reset($available);
            $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);
            $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
            $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : $user->ID;
            wp_safe_redirect(add_query_arg([
                'provider' => $single->get_id(),
                'token' => urlencode($token),
                'user_id' => $user_id,
            ], home_url("/{$slug}/")));
            exit;
        }

        if ($provider && isset($_GET['action']) && 'esia_callback' === $_GET['action']) {
            $this->handle_esia_callback();
            return;
        }

        if ($provider && $provider instanceof NG_Auth_Providers_SMS) {
            $this->render_sms_form($provider, $user, $available);
            return;
        }

        if ($provider && $provider instanceof NG_Auth_Providers_ESIA) {
            $this->render_esia_button($provider, $user, $available);
            return;
        }

        $this->render_provider_selection($user, $available);
    }

    /**
     * Определение пользователя, проходящего верификацию.
     *
     * Проверяет токен из URL или использует текущего авторизованного пользователя.
     *
     * @return WP_User|null Пользователь или null, если не удалось определить.
     */
    private function get_current_verification_user(): ?WP_User
    {
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        NG_Auth_Log_Logger::info('Resolving verification user', [
            'user_id' => $user_id,
            'token_len' => strlen($token),
            'is_logged_in' => is_user_logged_in(),
            'auth_handler_exists' => $this->auth_handler ? 'yes' : 'no',
        ]);

        if (0 < $user_id && '' !== $token) {
            $user = get_userdata($user_id);
            if ($user instanceof WP_User) {
                // Пробуем новый HMAC-токен (из auth_handler).
                if ($this->auth_handler) {
                    $valid = $this->auth_handler->validate_verification_token($user, $token);
                    NG_Auth_Log_Logger::info('HMAC token check', [
                        'user_id' => $user->ID,
                        'valid' => $valid,
                    ]);
                    if ($valid) {
                        return $user;
                    }
                }

                // Fallback: старый wp_create_nonce для обратной совместимости.
                $nonce_valid = wp_verify_nonce($token, 'ng_auth_verify_' . $user->ID);
                NG_Auth_Log_Logger::info('Nonce fallback check', [
                    'user_id' => $user->ID,
                    'valid' => (bool) $nonce_valid,
                ]);
                if ($nonce_valid) {
                    return $user;
                }

                NG_Auth_Log_Logger::warning('Token invalid, user not resolved', [
                    'user_id' => $user_id,
                    'hmac_valid' => $this->auth_handler ? $this->auth_handler->validate_verification_token($user, $token) : 'no_handler',
                    'nonce_valid' => (bool) $nonce_valid,
                ]);
            }
        }

        if (is_user_logged_in()) {
            $logged_user = wp_get_current_user();
            NG_Auth_Log_Logger::info('Using logged-in user', [
                'user_id' => $logged_user->ID,
            ]);
            return $logged_user;
        }

        NG_Auth_Log_Logger::warning('No user resolved', ['user_id_param' => $user_id]);
        return null;
    }

    /**
     * Поиск провайдера по идентификатору среди доступных.
     *
     * @param string $id Идентификатор провайдера.
     * @return NG_Auth_Contracts_Provider|null Провайдер или null.
     */
    private function find_provider(string $id): ?NG_Auth_Contracts_Provider
    {
        foreach ($this->providers as $provider) {
            if ($provider->get_id() === $id && $provider->is_available()) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Получение списка доступных провайдеров, отсортированных по приоритету.
     *
     * Результат фильтруется через ng_auth_available_providers.
     *
     * @return NG_Auth_Contracts_Provider[] Отсортированный массив провайдеров.
     */
    private function get_available_providers(): array
    {
        $available = array_filter($this->providers, function (NG_Auth_Contracts_Provider $p): bool {
            return $p->is_available();
        });

        $available = apply_filters('ng_auth_available_providers', $available);

        usort($available, function (NG_Auth_Contracts_Provider $a, NG_Auth_Contracts_Provider $b): int {
            return $a->get_priority() <=> $b->get_priority();
        });

        return $available;
    }

    /**
     * Отрисовка страницы выбора провайдера верификации.
     *
     * @param WP_User                        $user      Пользователь.
     * @param NG_Auth_Contracts_Provider[]   $providers Доступные провайдеры.
     * @return void
     */
    private function render_provider_selection(WP_User $user, array $providers): void
    {
        $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        $this->load_template('provider-selector.php', [
            'providers' => $providers,
            'user' => $user,
            'base_url' => home_url("/{$slug}/"),
            'token' => $token,
        ]);
    }

    /**
     * Отрисовка формы ввода SMS-кода.
     *
     * @param NG_Auth_Providers_SMS          $provider  SMS-провайдер.
     * @param WP_User                        $user      Пользователь.
     * @param NG_Auth_Contracts_Provider[]   $providers Все доступные провайдеры.
     * @return void
     */
    private function render_sms_form(NG_Auth_Providers_SMS $provider, WP_User $user, array $providers): void
    {
        $attempts = $this->storage->get_otp_attempts($user);
        $ttl = $this->storage->get_otp_ttl($user);
        $settings = get_option('ng_auth_settings', []);
        $max_attempts = (int) ($settings['otp_max_attempts'] ?? 5);

        // Проверяем, зафиксирован ли телефон в токене верификации.
        $phone_locked = '';
        $verify_phone_hash = get_user_meta($user->ID, 'ng_auth_verify_phone_hash', true);
        if ('' !== $verify_phone_hash) {
            $phone_hash = $this->storage->get_phone_hash($user);
            if ('' !== $phone_hash && $phone_hash === $verify_phone_hash) {
                // Телефон совпадает — показываем readonly с оригинальным номером.
                $phone_locked = $phone_hash;
            }
        }

        // В тестовом режиме показываем OTP-код из последнего лога.
        $test_otp_code = '';
        if (0 < $attempts && $provider->is_test_mode()) {
            $log = $this->storage->get_verification_log($user->ID, 5);
            foreach ($log as $entry) {
                if ('test_otp' === $entry['action'] && 'pending' === $entry['status']) {
                    if (preg_match('/Тестовый код: (\d{4,6})/', $entry['message'], $m)) {
                        $test_otp_code = $m[1];
                        break;
                    }
                }
            }
        }

        $this->load_template('verification-form.php', [
            'provider' => $provider,
            'user' => $user,
            'attempts' => $attempts,
            'max_attempts' => $max_attempts,
            'ttl' => $ttl,
            'providers' => $providers,
            'phone_locked' => $phone_locked,
            'test_otp_code' => $test_otp_code,
        ]);
    }

    /**
     * Обработка нажатия кнопки «Войти через Госуслуги».
     *
     * Инициирует OAuth-процесс и перенаправляет пользователя на портал ЕСИА.
     *
     * @param NG_Auth_Providers_ESIA         $provider  Провайдер ЕСИА.
     * @param WP_User                        $user      Пользователь.
     * @param NG_Auth_Contracts_Provider[]   $providers Все доступные провайдеры.
     * @return void
     */
    private function render_esia_button(NG_Auth_Providers_ESIA $provider, WP_User $user, array $providers): void
    {
        $result = $provider->initiate_verification($user);
        if ('' !== $result->get_redirect_url()) {
            wp_redirect(esc_url_raw($result->get_redirect_url()));
            exit;
        }

        $this->load_template('verification-form.php', [
            'provider' => $provider,
            'user' => $user,
            'error' => $result->get_message(),
            'providers' => $providers,
        ]);
    }

    /**
     * AJAX-обработчик отправки OTP.
     *
     * Принимает provider и user_id, вызывает initiate_verification.
     *
     * @return void
     */
    public function handle_send_otp(): void
    {
        try {
            if (!wp_verify_nonce(
                isset($_REQUEST['ng_auth_nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['ng_auth_nonce'])) : '',
                'ng_auth_verify'
            )) {
                wp_send_json(['success' => false, 'message' => __('Ошибка безопасности.', 'ng-auth')]);
            }

            $provider_id = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
            $provider = $this->find_provider($provider_id);

            if (!$provider instanceof NG_Auth_Providers_SMS) {
                wp_send_json(['success' => false, 'message' => __('Провайдер не найден.', 'ng-auth')]);
            }

            $user = $this->resolve_ajax_user();
            if (!$user instanceof WP_User) {
                wp_send_json(['success' => false, 'message' => __('Пользователь не найден.', 'ng-auth')]);
            }

            $phone = isset($_POST['ng_auth_phone'])
                ? sanitize_text_field(wp_unslash($_POST['ng_auth_phone']))
                : '';

            // Проверяем, не привязан ли телефон к другому пользователю.
            if ('' !== $phone) {
                $phone_raw = preg_replace('/[^0-9]/', '', $phone);
                $existing_user_id = $this->find_user_by_phone_raw($phone_raw, $user->ID);
                if (0 !== $existing_user_id) {
                    $return_url = $this->auth_handler ? $this->auth_handler->get_return_url($user) : home_url();
                    $return_url = add_query_arg([
                        'ng_auth' => 'duplicate',
                        'until' => 0,
                    ], $return_url);

                    NG_Auth_Log_Logger::warning('OTP blocked: phone already used by another user', [
                        'user_id' => $user->ID,
                        'phone' => substr($phone, 0, 4) . '***',
                        'existing_user_id' => $existing_user_id,
                    ]);
                    wp_send_json([
                        'success' => false,
                        'redirect' => $return_url,
                        'duplicate_phone' => true,
                    ]);
                }
            }

            // Проверяем блокировку телефона перед отправкой.
            if ('' !== $phone) {
                $lockout = new NG_Auth_Core_Lockout_Service();
                if ($lockout->is_phone_locked($phone)) {
                    $remaining = $lockout->phone_lock_remaining($phone);
                    $return_url = $this->auth_handler ? $this->auth_handler->get_return_url($user) : home_url();
                    $return_url = add_query_arg([
                        'ng_auth' => 'lockout',
                        'until' => time() + $remaining,
                    ], $return_url);

                    NG_Auth_Log_Logger::warning('OTP blocked: phone locked out', [
                        'user_id' => $user->ID,
                        'remaining' => $remaining,
                    ]);
                    wp_send_json([
                        'success' => false,
                        'redirect' => $return_url,
                        'locked_out' => true,
                    ]);
                }

                add_filter('ng_auth_user_phone', function () use ($phone): string {
                    return $phone;
                });
            }

            NG_Auth_Log_Logger::info('OTP send requested', [
                'provider' => $provider_id,
                'user_id' => $user->ID,
                'phone' => substr($phone, 0, 4) . '***',
            ]);

            $result = $provider->initiate_verification($user);

            $response = [
                'success' => $result->is_success(),
                'message' => $result->get_message(),
                'ttl' => $this->storage->get_otp_ttl($user),
            ];

            // После успешной отправки — фиксируем телефон в токене.
            if ($result->is_success() && $this->auth_handler && '' !== $phone) {
                $new_token = $this->auth_handler->update_verification_token_phone($user, $phone);
                $response['token'] = $new_token;
            }

            // В тестовом режиме — возвращаем OTP-код клиенту для автозаполнения.
            if ($result->is_success() && $provider->is_test_mode()) {
                $log = $this->storage->get_verification_log($user->ID, 3);
                foreach ($log as $entry) {
                    if ('test_otp' === $entry['action'] && preg_match('/(\d{4,6})/', $entry['message'], $m)) {
                        $response['code'] = $m[1];
                        break;
                    }
                }
            }

            wp_send_json($response);
        } catch (\Throwable $e) {
            NG_Auth_Log_Logger::error('OTP send error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            wp_send_json(['success' => false, 'message' => __('Внутренняя ошибка.', 'ng-auth')]);
        }
    }

    /**
     * AJAX-обработчик проверки OTP-кода.
     */
    public function handle_verify_code(): void
    {
        try {
            if (!wp_verify_nonce(
                isset($_REQUEST['ng_auth_nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['ng_auth_nonce'])) : '',
                'ng_auth_verify'
            )) {
                wp_send_json(['success' => false, 'message' => __('Ошибка безопасности.', 'ng-auth')]);
            }

            $provider_id = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
            $provider = $this->find_provider($provider_id);

            if (!$provider instanceof NG_Auth_Providers_SMS) {
                wp_send_json(['success' => false, 'message' => __('Провайдер не найден.', 'ng-auth')]);
            }

            $user = $this->resolve_ajax_user();
            if (!$user instanceof WP_User) {
                wp_send_json(['success' => false, 'message' => __('Пользователь не найден.', 'ng-auth')]);
            }

            $data = [
                'code' => isset($_POST['ng_auth_code'])
                    ? sanitize_text_field(wp_unslash($_POST['ng_auth_code']))
                    : '',
                'state' => '',
            ];

            $result = $provider->verify($user, $data);

            if ($result->is_success()) {
                if (!is_user_logged_in()) {
                    wp_set_auth_cookie($user->ID);
                }
                $redirect = apply_filters('ng_auth_verification_redirect', home_url(), $user);
                wp_send_json([
                    'success' => true,
                    'message' => $result->get_message(),
                    'redirect' => $redirect,
                ]);
            }

            // При локауте — добавляем notice и редиректим на исходную страницу.
            $result_data = $result->get_data();
            if (!empty($result_data['locked_out'])) {
                $lockout = new NG_Auth_Core_Lockout_Service();
                $remaining = $lockout->email_lock_remaining($user->user_email);

                NG_Auth_UI_Notice_Controller::add('error', $result->get_message());
                $return_url = $this->auth_handler ? $this->auth_handler->get_return_url($user) : home_url();
                $return_url = add_query_arg([
                    'ng_auth' => 'lockout',
                    'until' => time() + $remaining,
                ], $return_url);

                wp_send_json([
                    'success' => false,
                    'message' => $result->get_message(),
                    'redirect' => $return_url,
                    'locked_out' => true,
                ]);
            }

            wp_send_json([
                'success' => false,
                'message' => $result->get_message(),
            ]);
        } catch (\Throwable $e) {
            NG_Auth_Log_Logger::error('OTP verify error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            wp_send_json(['success' => false, 'message' => __('Внутренняя ошибка.', 'ng-auth')]);
        }
    }

    /**
     * Определяет пользователя для AJAX-запроса.
     *
     * @return WP_User|null
     */
    private function resolve_ajax_user(): ?WP_User
    {
        if (is_user_logged_in()) {
            return wp_get_current_user();
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if (0 < $user_id) {
            return get_userdata($user_id);
        }

        return null;
    }

    /**
     * Обработчик OAuth-колбэка от ЕСИА.
     *
     * При успешной авторизации — подтверждает пользователя,
     * устанавливает куку и перенаправляет на домашнюю страницу.
     *
     * @return void
     */
    public function handle_esia_callback(): void
    {
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        $user = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            wp_die(esc_html__('Пользователь не найден.', 'ng-auth'), 400);
        }

        $provider = $this->find_provider('esia');
        if (!$provider instanceof NG_Auth_Providers_ESIA) {
            wp_die(esc_html__('ЕСИА не настроен.', 'ng-auth'), 400);
        }

        if (!wp_verify_nonce($token, 'ng_auth_verify_' . $user_id)) {
            wp_die(esc_html__('Недействительный токен.', 'ng-auth'), 400);
        }

        $result = $provider->verify($user, ['code' => $code, 'state' => $state]);

        if ($result->is_success()) {
            wp_set_auth_cookie($user->ID);
            wp_safe_redirect(apply_filters('ng_auth_verification_redirect', home_url(), $user));
            exit;
        }

        $slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);
        wp_safe_redirect(home_url("/{$slug}/?error=" . urlencode($result->get_message())));
        exit;
    }

    /**
     * Поиск пользователя по сырому номеру телефона (без +, только цифры).
     *
     * Исключает переданный user_id из поиска (чтобы не блокировать повторную
     * отправку кода тому же пользователю).
     *
     * @param string $phone_raw   Номер телефона (только цифры).
     * @param int    $exclude_id  ID пользователя для исключения.
     * @return int ID найденного пользователя, 0 если не найден.
     */
    private function find_user_by_phone_raw(string $phone_raw, int $exclude_id = 0): int
    {
        global $wpdb;

        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
            WHERE meta_key = 'ng_auth_phone_raw' AND meta_value = %s AND user_id != %d
            LIMIT 1",
            $phone_raw,
            $exclude_id
        ));

        return (int) $user_id;
    }

    /**
     * Загрузка шаблона отображения.
     *
     * Сначала ищет шаблон в теме (wp-content/themes/{theme}/ng-auth/),
     * затем в директории плагина (templates/). Если файл не найден —
     * выводит базовую HTML-заглушку.
     *
     * @param string               $template Имя файла шаблона.
     * @param array<string, mixed> $data     Данные для передачи в шаблон.
     * @return void
     */
    private function load_template(string $template, array $data = []): void
    {
        $theme_template = get_stylesheet_directory() . '/ng-auth/' . $template;
        $plugin_template = NG_AUTH_DIR . 'templates/' . $template;

        $file = file_exists($theme_template) ? $theme_template : $plugin_template;

        if (!file_exists($file)) {
            echo '<div class="ng-auth-verify">';
            echo '<h2>' . esc_html__('Подтверждение личности', 'ng-auth') . '</h2>';
            if (isset($data['message'])) {
                echo '<p>' . esc_html($data['message']) . '</p>';
            }
            echo '</div>';
            return;
        }

        extract($data);
        include $file;
    }
}
