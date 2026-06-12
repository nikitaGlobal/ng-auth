<?php

declare(strict_types=1);

/**
 * Контракт провайдера верификации.
 *
 * Определяет обязательные методы для любого провайдера
 * подтверждения личности: идентификация, доступность,
 * настройки в админке, запуск и проверка верификации.
 *
 * @package NG_Auth
 */

interface NG_Auth_Contracts_Provider
{
    /**
     * Уникальный идентификатор провайдера.
     *
     * @return string slug-идентификатор (exolve, smsaero, esia).
     */
    public function get_id(): string;

    /**
     * Человекочитаемое название провайдера.
     *
     * @return string название для отображения в интерфейсе.
     */
    public function get_name(): string;

    /**
     * Краткое описание способа верификации.
     *
     * @return string описание для пользователя и админа.
     */
    public function get_description(): string;

    /**
     * URL логотипа провайдера.
     *
     * @return string URL изображения или пустая строка.
     */
    public function get_logo_url(): string;

    /**
     * Ссылка на сайт провайдера.
     *
     * @return string URL или пустая строка.
     */
    public function get_provider_url(): string;

    /**
     * Инструкция по подключению для администратора.
     *
     * @return string текст инструкции или пустая строка.
     */
    public function get_instructions(): string;

    /**
     * Доступен ли провайдер для использования.
     *
     * Проверяет, включён ли провайдер в настройках и настроены ли ключи.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Приоритет отображения в списке выбора.
     *
     * @return int чем меньше число, тем выше в списке.
     */
    public function get_priority(): int;

    /**
     * Поля для страницы настроек провайдера в админке.
     *
     * @deprecated Используйте init_form_fields().
     * @return array[] массив с ключами: label, type, default.
     */
    public function get_admin_fields(): array;

    /**
     * Возвращает массив полей формы для страницы настроек провайдера.
     *
     * Каждый элемент массива — это ассоциативный массив с ключами:
     * label (string), type (string), default (mixed).
     *
     * @return array<string, array{label: string, type: string, default: mixed}>
     */
    public function init_form_fields(): array;

    /**
     * Сохраняет настройки провайдера из данных POST-запроса.
     *
     * Вызывается при отправке формы на странице настроек провайдера.
     *
     * @return void
     */
    public function process_admin_options(): void;

    /**
     * Запускает процесс верификации для пользователя.
     *
     * Для SMS — отправляет OTP. Для OAuth — возвращает URL редиректа.
     *
     * @param WP_User $user пользователь, проходящий верификацию.
     * @return NG_Auth_Verification_Result результат инициализации.
     */
    public function initiate_verification(WP_User $user): NG_Auth_Verification_Result;

    /**
     * Проверяет данные верификации, введённые пользователем.
     *
     * Для SMS — сверяет OTP-код. Для OAuth — обменивает авторизационный код.
     *
     * @param WP_User $user пользователь.
     * @param array   $data данные верификации (code, state и т.д.).
     * @return NG_Auth_Verification_Result результат проверки.
     */
    public function verify(WP_User $user, array $data): NG_Auth_Verification_Result;
}
