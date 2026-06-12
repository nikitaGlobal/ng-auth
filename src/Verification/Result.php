<?php

/**
 * Результат операции верификации.
 *
 * Инкапсулирует статус операции (успех/неудача/перенаправление),
 * сообщение и дополнительные данные.
 *
 * @package NG_Auth
 */

declare(strict_types=1);

class NG_Auth_Verification_Result
{
    /**
     * Флаг успешности операции.
     *
     * @var bool
     */
    private bool $success;

    /**
     * Сообщение для пользователя.
     *
     * @var string
     */
    private string $message;

    /**
     * Дополнительные данные результата.
     *
     * @var array
     */
    private array $data;

    /**
     * URL для перенаправления (если применимо).
     *
     * @var string
     */
    private string $redirect_url;

    /**
     * Конструктор результата верификации.
     *
     * @param bool   $success      Флаг успешности.
     * @param string $message      Сообщение для пользователя.
     * @param array  $data         Дополнительные данные.
     * @param string $redirect_url URL перенаправления.
     */
    public function __construct(bool $success, string $message = '', array $data = [], string $redirect_url = '')
    {
        $this->success = $success;
        $this->message = $message;
        $this->data = $data;
        $this->redirect_url = $redirect_url;
    }

    /**
     * Создание успешного результата.
     *
     * @param string $message Сообщение об успехе.
     * @param array  $data    Дополнительные данные.
     * @return self
     */
    public static function success(string $message = '', array $data = []): self
    {
        return new self(true, $message, $data);
    }

    /**
     * Создание результата с ошибкой.
     *
     * @param string $message Сообщение об ошибке.
     * @param array  $data    Дополнительные данные.
     * @return self
     */
    public static function failure(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    /**
     * Создание результата с перенаправлением.
     *
     * Возвращает результат с флагом неуспеха, но с URL для перенаправления.
     *
     * @param string $url     URL для перенаправления.
     * @param string $message Сообщение.
     * @param array  $data    Дополнительные данные.
     * @return self
     */
    public static function redirect(string $url, string $message = '', array $data = []): self
    {
        $result = new self(false, $message, $data);
        $result->redirect_url = $url;
        return $result;
    }

    /**
     * Проверка, является ли результат успешным.
     *
     * @return bool
     */
    public function is_success(): bool
    {
        return $this->success;
    }

    /**
     * Получение сообщения результата.
     *
     * @return string
     */
    public function get_message(): string
    {
        return $this->message;
    }

    /**
     * Получение дополнительных данных результата.
     *
     * @return array
     */
    public function get_data(): array
    {
        return $this->data;
    }

    /**
     * Получение URL перенаправления.
     *
     * @return string
     */
    public function get_redirect_url(): string
    {
        return $this->redirect_url;
    }
}
