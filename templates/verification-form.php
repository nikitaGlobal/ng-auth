<?php
/**
 * Template: Verification form for SMS providers.
 *
 * @var NG_Auth_Providers_SMS             $provider
 * @var WP_User                           $user
 * @var int                               $attempts
 * @var int                               $max_attempts
 * @var int                               $ttl
 * @var string                            $phone_locked
 * @var string                            $test_otp_code
 */

if (!defined('ABSPATH')) {
    exit;
}

$slug = apply_filters('ng_auth_verify_slug', NG_AUTH_VERIFY_SLUG);
$token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
$user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : $user->ID;
$provider_id = $provider->get_id();
$settings = get_option('ng_auth_settings', []);
$resend_interval = (int) ($settings['otp_resend_interval'] ?? 60);
$otp_length = (int) ($settings['otp_length'] ?? 6);
$otp_sent = $attempts > 0 || $ttl > 0;
$phone_locked = $phone_locked ?? '';
$test_otp_code = $test_otp_code ?? '';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e('Подтверждение личности', 'ng-auth'); ?> — <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <style>
        .ng-auth-container { max-width: 480px; margin: 80px auto; padding: 32px; background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); }
        .ng-auth-container h2 { margin: 0 0 8px; font-size: 22px; }
        .ng-auth-container p { margin: 0 0 16px; color: #666; }
        .ng-auth-field { margin-bottom: 16px; }
        .ng-auth-field label { display: block; margin-bottom: 4px; font-weight: 600; }
        .ng-auth-field input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; box-sizing: border-box; }
        .ng-auth-field input[readonly] { background: #f9fafb; color: #666; }
        .ng-auth-field input:focus { border-color: #2271b1; outline: none; box-shadow: 0 0 0 1px #2271b1; }
        .ng-auth-btn { display: block; width: 100%; padding: 12px; background: #2271b1; color: #fff; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
        .ng-auth-btn:hover { background: #135e96; }
        .ng-auth-btn:disabled { background: #ccc; cursor: not-allowed; }
        .ng-auth-btn-secondary { background: #fff; color: #2271b1; border: 1px solid #2271b1; }
        .ng-auth-btn-secondary:hover { background: #f0f6fc; }
        .ng-auth-btn-secondary:disabled { background: #f9fafb; color: #999; border-color: #ddd; cursor: not-allowed; }
        .ng-auth-error { padding: 12px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; color: #dc2626; margin-bottom: 16px; display: none; }
        .ng-auth-success { padding: 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; color: #16a34a; margin-bottom: 16px; display: none; }
        .ng-auth-back { display: block; margin-top: 16px; text-align: center; font-size: 14px; }
    </style>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="ng-auth-container" id="ng-auth-verification-app">
    <h2><?php esc_html_e('Подтверждение личности', 'ng-auth'); ?></h2>
    <p><?php echo esc_html($provider->get_description()); ?></p>

    <div class="ng-auth-error" id="ng-auth-error"></div>
    <div class="ng-auth-success" id="ng-auth-success"></div>

    <form id="ng-auth-verification-form" method="post" action="">
        <?php wp_nonce_field('ng_auth_verify', 'ng_auth_nonce'); ?>

        <!-- Секция номера телефона -->
        <div id="ng-auth-send-section" class="ng-auth-send-section" style="display:<?php echo $otp_sent ? 'none' : 'block'; ?>">
            <div class="ng-auth-field">
                <label for="ng-auth-phone"><?php esc_html_e('Номер телефона', 'ng-auth'); ?></label>
                <input type="tel" id="ng-auth-phone" name="ng_auth_phone"
                       value="<?php echo esc_attr($phone_value ?? ''); ?>"
                       <?php echo ($otp_sent || '' !== $phone_locked) ? 'readonly' : ''; ?>
                       placeholder="+7 (999) 123-45-67"
                       autocomplete="tel"
                       required />
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="ng-auth-btn" id="ng-auth-send-btn">
                    <?php esc_html_e('Отправить код', 'ng-auth'); ?>
                </button>
                <button type="button" class="ng-auth-btn ng-auth-btn-secondary" id="ng-auth-cancel-btn">
                    <?php esc_html_e('Отменить', 'ng-auth'); ?>
                </button>
            </div>
        </div>

        <!-- Секция кода подтверждения -->
        <div id="ng-auth-code-section" class="ng-auth-code-section" style="display:<?php echo $otp_sent ? 'block' : 'none'; ?>">
            <!-- Поле телефона (readonly после отправки) -->
            <div class="ng-auth-field" id="ng-auth-phone-readonly-wrap" style="<?php echo $otp_sent ? '' : 'display:none;'; ?>">
                <label for="ng-auth-phone-readonly"><?php esc_html_e('Номер телефона', 'ng-auth'); ?></label>
                <input type="tel" id="ng-auth-phone-readonly" value="<?php echo $phone_value ?? ''; ?>" readonly />
            </div>

            <div class="ng-auth-field">
                <label for="ng-auth-code"><?php esc_html_e('Код подтверждения', 'ng-auth'); ?></label>
                <input type="text" id="ng-auth-code" name="ng_auth_code"
                       value="<?php echo esc_attr($test_otp_code); ?>"
                       inputmode="numeric" maxlength="<?php echo (int) $otp_length; ?>"
                       autocomplete="one-time-code"
                       placeholder="<?php echo esc_attr(str_repeat('0', min(6, (int) $otp_length))); ?>"
                       required />
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="button" class="ng-auth-btn" id="ng-auth-verify-btn">
                    <?php esc_html_e('Подтвердить', 'ng-auth'); ?>
                </button>
                <button type="button" class="ng-auth-btn ng-auth-btn-secondary" id="ng-auth-cancel-btn2">
                    <?php esc_html_e('Отменить', 'ng-auth'); ?>
                </button>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px;">
                <button type="button" class="ng-auth-btn-secondary ng-auth-resend-btn" id="ng-auth-resend-btn" disabled>
                    <?php esc_html_e('Отправить код повторно', 'ng-auth'); ?>
                </button>
                <span id="ng-auth-timer" style="font-size: 14px; color: #666;"></span>
            </div>
        </div>
    </form>
</div>

<script>
(function() {
    'use strict';

    var config = {
        ttl: <?php echo (int) $ttl; ?>,
        resendInterval: <?php echo (int) $resend_interval; ?>,
        providerId: <?php echo wp_json_encode($provider_id); ?>,
        userId: <?php echo (int) $user_id; ?>,
        nonce: (document.querySelector('[name="ng_auth_nonce"]') || {}).value || '',
        ajaxUrl: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
        otpSent: <?php echo $otp_sent ? 'true' : 'false'; ?>,
        cancelUrl: <?php echo wp_json_encode(wp_get_referer() ?: wp_login_url()); ?>,

    };

    function $(id) { return document.getElementById(id); }

    var sendBtn = $('ng-auth-send-btn');
    var verifyBtn = $('ng-auth-verify-btn');
    var resendBtn = $('ng-auth-resend-btn');
    var cancelBtn = $('ng-auth-cancel-btn');
    var cancelBtn2 = $('ng-auth-cancel-btn2');
    var phoneInput = $('ng-auth-phone');
    var phoneReadonly = $('ng-auth-phone-readonly');
    var codeInput = $('ng-auth-code');
    var errorEl = $('ng-auth-error');
    var successEl = $('ng-auth-success');
    var sendSection = $('ng-auth-send-section');
    var codeSection = $('ng-auth-code-section');
    var phoneReadonlyWrap = $('ng-auth-phone-readonly-wrap');
    var timerEl = $('ng-auth-timer');

    var otpTimerId = null;
    var resendTimerId = null;
    var sendTimerId = null;

    function showMessage(el, text) {
        el.textContent = text;
        el.style.display = 'block';
    }

    function hideMessages() {
        errorEl.style.display = 'none';
        successEl.style.display = 'none';
    }

    function clearTimers() {
        if (otpTimerId) { clearTimeout(otpTimerId); otpTimerId = null; }
        if (resendTimerId) { clearTimeout(resendTimerId); resendTimerId = null; }
        if (sendTimerId) { clearTimeout(sendTimerId); sendTimerId = null; }
    }

    function updateNonce() {
        var el = document.querySelector('[name="ng_auth_nonce"]');
        if (el) config.nonce = el.value;
    }

    function maskPhone(input) {
        var val = input.value.replace(/[^\d]/g, '');
        if (val.length > 11) val = val.substring(0, 11);
        var formatted = '+';
        if (val.length > 0) formatted += val.substring(0, Math.min(1, val.length));
        if (val.length > 1) formatted += ' (' + val.substring(1, Math.min(4, val.length));
        if (val.length >= 4) formatted += ') ' + val.substring(4, Math.min(7, val.length));
        if (val.length >= 7) formatted += '-' + val.substring(7, Math.min(9, val.length));
        if (val.length >= 9) formatted += '-' + val.substring(9, 11);
        input.value = formatted;
    }

    function getRawPhone(input) {
        return input.value.replace(/[^\d]/g, '');
    }

    function lockPhone(readonlyVal) {
        if (phoneReadonly) phoneReadonly.value = readonlyVal;
        if (phoneReadonlyWrap) phoneReadonlyWrap.style.display = '';
        if (phoneInput) {
            phoneInput.value = readonlyVal;
            phoneInput.readOnly = true;
        }
        if (sendSection) sendSection.style.display = 'none';
    }

    function unlockPhone() {
        if (phoneInput) phoneInput.readOnly = false;
        if (phoneReadonlyWrap) phoneReadonlyWrap.style.display = 'none';
        if (sendSection) sendSection.style.display = 'block';
        if (codeSection) codeSection.style.display = 'none';
    }

    function showCodeSection() {
        if (sendSection) sendSection.style.display = 'none';
        if (codeSection) codeSection.style.display = 'block';
    }

    function showSendSection() {
        if (sendSection) sendSection.style.display = 'block';
        if (codeSection) codeSection.style.display = 'none';
    }

    function startOTPTimer() {
        if (!timerEl || config.ttl <= 0) return;
        if (otpTimerId) clearTimeout(otpTimerId);
        var remaining = config.ttl;

        function update() {
            if (remaining <= 0) {
                timerEl.textContent = 'Код истёк';
                return;
            }
            var m = Math.floor(remaining / 60);
            var s = remaining % 60;
            timerEl.textContent = 'Код действителен: ' + m + ':' + (s < 10 ? '0' : '') + s;
            remaining--;
            otpTimerId = setTimeout(update, 1000);
        }
        update();
    }

    function startSendTimer(btn) {
        if (!btn || config.resendInterval <= 0) return;
        if (sendTimerId) clearTimeout(sendTimerId);
        btn.disabled = true;
        var remaining = config.resendInterval;
        var originalText = btn.textContent;

        function update() {
            if (remaining <= 0) {
                btn.disabled = false;
                btn.textContent = originalText;
                return;
            }
            btn.textContent = originalText + ' (' + remaining + 'c)';
            remaining--;
            sendTimerId = setTimeout(update, 1000);
        }
        update();
    }

    function startResendTimer() {
        if (!resendBtn) return;
        if (resendTimerId) clearTimeout(resendTimerId);
        resendBtn.disabled = true;
        var remaining = config.resendInterval;

        function update() {
            if (remaining <= 0) {
                resendBtn.disabled = false;
                resendBtn.textContent = 'Отправить код повторно';
                return;
            }
            resendBtn.textContent = 'Отправить код повторно (' + remaining + 'c)';
            remaining--;
            resendTimerId = setTimeout(update, 1000);
        }
        update();
    }

    function sendOTP(phone) {
        updateNonce();
        var formData = new URLSearchParams();
        formData.append('action', 'ng_auth_send_otp');
        formData.append('ng_auth_nonce', config.nonce);
        formData.append('ng_auth_phone', phone || '');
        formData.append('provider', config.providerId);
        formData.append('user_id', config.userId);
        return fetch(config.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        }).then(function(r) { return r.json(); });
    }

    function verifyCode(code) {
        updateNonce();
        var formData = new URLSearchParams();
        formData.append('action', 'ng_auth_verify_code');
        formData.append('ng_auth_nonce', config.nonce);
        formData.append('ng_auth_code', code);
        formData.append('provider', config.providerId);
        formData.append('user_id', config.userId);
        return fetch(config.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        }).then(function(r) { return r.json(); });
    }

    // --- Event bindings ---

    // Форматирование номера при вводе
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            maskPhone(this);
        });
    }

    // Кнопка «Отправить код»
    if (sendBtn) {
        sendBtn.addEventListener('click', function() {
            hideMessages();
            var phone = getRawPhone(phoneInput);

            if (phone.length < 10) {
                showMessage(errorEl, 'Введите корректный номер телефона (минимум 10 цифр).');
                return;
            }

            sendBtn.disabled = true;
            var formattedPhone = '+' + phone;
            lockPhone(formattedPhone);

            sendOTP(formattedPhone)
                .then(function(data) {
                    if (data.success) {
                        config.ttl = data.ttl || config.ttl;
                        // Фиксируем телефон в токене — обновляем URL, чтобы перезагрузка не сбросила.
                        if (data.token) {
                            var url = new URL(window.location.href);
                            url.searchParams.set('token', data.token);
                            window.history.replaceState({}, '', url.toString());
                        }
                        // В тестовом режиме сервер возвращает код — автозаполняем.
                        if (data.code && codeInput) {
                            codeInput.value = data.code;
                        }
                        updateNonce();
                        showCodeSection();
                        showMessage(successEl, data.message);
                        startOTPTimer();
                        startResendTimer();
                    } else {
                        // При блокировке телефона или дубликате — редирект с модальным окном.
                        if ((data.locked_out || data.duplicate_phone) && data.redirect) {
                            window.location.href = data.redirect;
                            return;
                        }
                        showMessage(errorEl, data.message);
                        unlockPhone();
                        startSendTimer(sendBtn);
                    }
                })
                .catch(function(error) {
                    showMessage(errorEl, 'Ошибка сети: ' + (error.message || error));
                    unlockPhone();
                    startSendTimer(sendBtn);
                });
        });
    }

    // Кнопка «Подтвердить»
    if (verifyBtn) {
        verifyBtn.addEventListener('click', function() {
            hideMessages();
            var code = codeInput ? codeInput.value.trim() : '';

            if (!code) {
                showMessage(errorEl, 'Введите код подтверждения.');
                return;
            }

            verifyBtn.disabled = true;

            verifyCode(code)
                .then(function(data) {
                    if (data.success) {
                        showMessage(successEl, data.message);
                        if (data.redirect) {
                            window.location.href = data.redirect;
                        }
                    } else {
                        // При локауте — редирект на страницу входа/регистрации.
                        if (data.locked_out && data.redirect) {
                            window.location.href = data.redirect;
                            return;
                        }
                        showMessage(errorEl, data.message);
                        verifyBtn.disabled = false;
                    }
                })
                .catch(function(error) {
                    showMessage(errorEl, 'Ошибка сети: ' + (error.message || error));
                    verifyBtn.disabled = false;
                });
        });
    }

    // Кнопка «Отправить код повторно»
    if (resendBtn) {
        resendBtn.addEventListener('click', function() {
            hideMessages();
            updateNonce();
            resendBtn.disabled = true;

            sendOTP('')
                .then(function(data) {
                    if (data.success) {
                        config.ttl = data.ttl || config.ttl;
                        if (data.code && codeInput) {
                            codeInput.value = data.code;
                        }
                        updateNonce();
                        showMessage(successEl, data.message);
                        startOTPTimer();
                        startResendTimer();
                    } else {
                        showMessage(errorEl, data.message);
                        startResendTimer();
                    }
                })
                .catch(function() {
                    showMessage(errorEl, 'Ошибка сети.');
                    startResendTimer();
                });
        });
    }

    // Кнопки «Отменить» — возврат на предыдущую страницу WordPress
    function cancelToPreviousPage() {
        window.location.href = config.cancelUrl;
    }

    if (cancelBtn) cancelBtn.addEventListener('click', cancelToPreviousPage);
    if (cancelBtn2) cancelBtn2.addEventListener('click', cancelToPreviousPage);

    // Кнопка «Отменить и сменить номер»
    // Предотвращаем обычный сабмит формы
    var form = $('ng-auth-verification-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
        });
    }

    // Инициализация таймеров при загрузке (если код уже отправлен)
    if (config.otpSent) {
        if (config.ttl > 0) startOTPTimer();
        if (config.resendInterval > 0) startResendTimer();
    }
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
