(function($) {
    'use strict';

    const NG_Auth = window.NG_Auth || {};

    NG_Auth.Verification = {
        ttl: 0,
        resendInterval: 60,
        providerId: '',
        userId: 0,
        nonce: '',
        ajaxUrl: '',
        resendTimerId: null,
        otpTimerId: null,

        sendTimerId: null,

    init: function(config) {
            this.ttl = config.ttl || 0;
            this.resendInterval = parseInt(config.resendInterval || 60, 10);
            this.providerId = config.providerId || '';
            this.userId = parseInt(config.userId || 0, 10);
            this.nonce = config.nonce || '';
            this.ajaxUrl = config.ajaxUrl || '';

            this.bindPhoneForm();
            this.bindCodeForm();
            this.bindResendBtn();
            this.initTimers();
        },

        showMessage: function(text, type) {
            let el = document.getElementById('ng-auth-message');
            if (el) {
                el.innerHTML = '<div class="ng-auth-' + type + '">' + text + '</div>';
            }
        },

        clearTimers: function() {
            if (this.otpTimerId) { clearTimeout(this.otpTimerId); this.otpTimerId = null; }
            if (this.resendTimerId) { clearTimeout(this.resendTimerId); this.resendTimerId = null; }
            if (this.sendTimerId) { clearTimeout(this.sendTimerId); this.sendTimerId = null; }
        },

        initTimers: function() {
            let codeForm = document.getElementById('ng-auth-code-form');
            if (codeForm && this.ttl > 0) {
                this.startOTPTimer();
            }
            if (codeForm && this.resendInterval > 0) {
                this.startResendTimer();
            }
        },

        startOTPTimer: function() {
            let el = document.getElementById('ng-auth-timer');
            if (!el || this.ttl <= 0) return;

            if (this.otpTimerId) clearTimeout(this.otpTimerId);
            let remaining = this.ttl;
            let self = this;
            el.style.display = 'block';

            function update() {
                let m = Math.floor(remaining / 60);
                let s = remaining % 60;
                el.textContent = 'Код действителен: ' + m + ':' + (s < 10 ? '0' : '') + s;
                if (remaining <= 0) {
                    el.textContent = 'Код истёк';
                    return;
                }
                remaining--;
                self.otpTimerId = setTimeout(update, 1000);
            }
            update();
        },

        startResendTimer: function() {
            let btn = document.getElementById('ng-auth-resend-btn');
            if (!btn || this.resendInterval <= 0) return;

            if (this.resendTimerId) clearTimeout(this.resendTimerId);
            btn.disabled = true;
            let remaining = this.resendInterval;
            let self = this;

            function update() {
                if (remaining <= 0) {
                    btn.disabled = false;
                    btn.textContent = 'Отправить код повторно';
                    return;
                }
                let m = Math.floor(remaining / 60);
                let s = remaining % 60;
                let timeStr = m > 0 ? m + ':' + (s < 10 ? '0' : '') + s : s + 'c';
                btn.textContent = 'Отправить код повторно (' + timeStr + ')';
                remaining--;
                self.resendTimerId = setTimeout(update, 1000);
            }
            update();
        },

        startSendTimer: function(btn) {
            if (!btn || this.resendInterval <= 0) return;

            if (this.sendTimerId) clearTimeout(this.sendTimerId);
            btn.disabled = true;
            let remaining = this.resendInterval;
            let originalText = btn.textContent;
            let self = this;

            function update() {
                if (remaining <= 0) {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    return;
                }
                let s = remaining;
                btn.textContent = originalText + ' (' + s + 'c)';
                remaining--;
                self.sendTimerId = setTimeout(update, 1000);
            }
            update();
        },

        updateNonce: function() {
            let el = document.querySelector('[name="ng_auth_nonce"]');
            if (el) this.nonce = el.value;
        },

        sendOTP: function(phone) {
            let self = this;
            return $.post(this.ajaxUrl, {
                action: 'ng_auth_send_otp',
                ng_auth_nonce: this.nonce,
                ng_auth_phone: phone || '',
                provider: this.providerId,
                user_id: this.userId
            });
        },

        verifyCode: function(code) {
            return $.post(this.ajaxUrl, {
                action: 'ng_auth_verify_code',
                ng_auth_nonce: this.nonce,
                ng_auth_code: code,
                provider: this.providerId,
                user_id: this.userId
            });
        },

        bindPhoneForm: function() {
            let form = document.getElementById('ng-auth-phone-form');
            if (!form) return;

            let phoneInput = document.getElementById('ng-auth-phone');
            let sendBtn = document.getElementById('ng-auth-send-btn');
            let codeForm = document.getElementById('ng-auth-code-form');
            let self = this;

            phoneInput.addEventListener('input', function() {
                let val = this.value.replace(/[^\d]/g, '');
                if (val.length > 11) val = val.substring(0, 11);
                let formatted = '+';
                if (val.length > 0) formatted += val.substring(0, Math.min(1, val.length));
                if (val.length > 1) formatted += ' (' + val.substring(1, Math.min(4, val.length));
                if (val.length >= 4) formatted += ') ' + val.substring(4, Math.min(7, val.length));
                if (val.length >= 7) formatted += '-' + val.substring(7, Math.min(9, val.length));
                if (val.length >= 9) formatted += '-' + val.substring(9, 11);
                this.value = formatted;
            });

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                if (sendBtn) sendBtn.disabled = true;
                let phone = phoneInput.value.replace(/[^\d]/g, '');

                self.sendOTP('+' + phone)
                    .done(function(data) {
                        if (data.success) {
                            self.ttl = data.ttl || self.ttl;
                            form.style.display = 'none';
                            if (codeForm) codeForm.style.display = 'block';
                            self.updateNonce();
                            self.showMessage(data.message, 'success');
                            self.startOTPTimer();
                            self.startResendTimer();
                        } else {
                            self.showMessage(data.message, 'error');
                            self.startSendTimer(sendBtn);
                        }
                    })
                    .fail(function(jqXHR, status, error) {
                        self.showMessage('Ошибка сети: ' + error, 'error');
                        self.startSendTimer(sendBtn);
                        console.error('NG Auth OTP:', status, error);
                    });
            });
        },

        bindCodeForm: function() {
            let form = document.getElementById('ng-auth-code-form');
            if (!form) return;

            let self = this;

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                let verifyBtn = document.getElementById('ng-auth-verify-btn');
                if (verifyBtn) verifyBtn.disabled = true;
                let code = document.getElementById('ng-auth-code').value;

                self.verifyCode(code)
                    .done(function(data) {
                        if (data.success) {
                            self.showMessage(data.message, 'success');
                            if (data.redirect) window.location.href = data.redirect;
                        } else {
                            self.showMessage(data.message, 'error');
                            if (verifyBtn) verifyBtn.disabled = false;
                        }
                    })
                    .fail(function(jqXHR, status, error) {
                        self.showMessage('Ошибка сети: ' + error, 'error');
                        if (verifyBtn) verifyBtn.disabled = false;
                        console.error('NG Auth Verify:', status, error);
                    });
            });
        },

        bindResendBtn: function() {
            let btn = document.getElementById('ng-auth-resend-btn');
            if (!btn) return;

            let self = this;

            btn.addEventListener('click', function() {
                self.updateNonce();
                btn.disabled = true;

                self.sendOTP('')
                    .done(function(data) {
                        if (data.success) {
                            self.ttl = data.ttl || self.ttl;
                            self.updateNonce();
                            self.showMessage(data.message, 'success');
                            self.startOTPTimer();
                            self.startResendTimer();
                        } else {
                            self.showMessage(data.message, 'error');
                            self.startResendTimer();
                        }
                    })
                    .fail(function() {
                        self.showMessage('Ошибка сети.', 'error');
                        self.startResendTimer();
                    });
            });
        }
    };

    window.NG_Auth = NG_Auth;

})(jQuery);
