<?php
/**
 * Template: lockout modal.
 *
 * @var int $remaining Секунд до разблокировки.
 * @var int $hours     Часов.
 * @var int $minutes   Минут.
 * @var int $seconds   Секунд.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<style>
.ng-auth-lockout-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99999;
    display: flex; align-items: center; justify-content: center;
}
.ng-auth-lockout-modal {
    background: #fff; border-radius: 8px; padding: 32px; max-width: 420px; width: 90%;
    box-shadow: 0 4px 24px rgba(0,0,0,0.15); text-align: center;
}
.ng-auth-lockout-modal h3 {
    margin: 0 0 12px; font-size: 18px; color: #dc2626;
}
.ng-auth-lockout-modal p {
    margin: 0 0 16px; color: #666; font-size: 14px; line-height: 1.5;
}
.ng-auth-lockout-timer {
    font-size: 28px; font-weight: 700; color: #dc2626; margin-bottom: 16px;
    font-variant-numeric: tabular-nums;
}
.ng-auth-lockout-close {
    padding: 10px 24px; background: #dc2626; color: #fff; border: none;
    border-radius: 4px; cursor: pointer; font-size: 14px;
}
.ng-auth-lockout-close:hover { background: #b91c1c; }
</style>

<div class="ng-auth-lockout-overlay" id="ng-auth-lockout-overlay">
    <div class="ng-auth-lockout-modal">
        <?php if ('' !== $message): ?>
            <h3><?php esc_html_e('Номер телефона уже используется', 'ng-auth'); ?></h3>
            <p><?php echo esc_html($message); ?></p>
        <?php else: ?>
            <h3><?php esc_html_e('Доступ заблокирован', 'ng-auth'); ?></h3>
            <p><?php esc_html_e('Вы превысили количество попыток ввода кода подтверждения. Email и номер телефона временно заблокированы.', 'ng-auth'); ?></p>
            <div class="ng-auth-lockout-timer" id="ng-auth-lockout-timer"><?php
                printf('%02d:%02d:%02d', $hours, $minutes, $seconds);
            ?></div>
            <p><?php esc_html_e('Попробуйте снова после истечения этого времени.', 'ng-auth'); ?></p>
        <?php endif; ?>
        <button type="button" class="ng-auth-lockout-close" onclick="document.getElementById('ng-auth-lockout-overlay').remove();">
            <?php esc_html_e('Закрыть', 'ng-auth'); ?>
        </button>
    </div>
</div>

<?php if (0 < $remaining): ?>
<script>
(function() {
    var remaining = <?php echo (int) $remaining; ?>;
    var timerEl = document.getElementById('ng-auth-lockout-timer');
    if (!timerEl || remaining <= 0) return;

    function update() {
        if (remaining <= 0) {
            timerEl.textContent = '00:00:00';
            return;
        }
        var h = Math.floor(remaining / 3600);
        var m = Math.floor((remaining % 3600) / 60);
        var s = remaining % 60;
        timerEl.textContent = [
            (h < 10 ? '0' : '') + h,
            (m < 10 ? '0' : '') + m,
            (s < 10 ? '0' : '') + s
        ].join(':');
        remaining--;
        setTimeout(update, 1000);
    }
    update();
})();
</script>
