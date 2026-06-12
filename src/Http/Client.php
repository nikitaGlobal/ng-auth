<?php

declare(strict_types=1);

/**
 * HTTP-клиент плагина.
 *
 * Все внешние запросы проходят через этот класс.
 * Логирование запросов/ответов — только при http_logging=1 в настройках.
 *
 * @package NG_Auth
 */

class NG_Auth_Http_Client
{
    private int $timeout;
    private bool $log_requests;

    public function __construct(int $timeout = 10)
    {
        $this->timeout = $timeout;
        $settings = get_option('ng_auth_settings', []);
        $this->log_requests = !empty($settings['http_logging']);
    }

    /**
     * GET-запрос.
     *
     * @param string $url URL запроса.
     * @return array{body: string, code: int}|WP_Error
     */
    public function get(string $url)
    {
        if ($this->log_requests) {
            NG_Auth_Log_Logger::info('HTTP GET', ['url' => $url]);
        }

        $response = wp_remote_get($url, ['timeout' => $this->timeout]);

        return $this->parse_response($url, 'GET', $response);
    }

    /**
     * POST-запрос с JSON-телом.
     *
     * @param string $url     URL запроса.
     * @param array  $data    Данные для JSON-кодирования.
     * @param array  $headers Дополнительные HTTP-заголовки.
     * @return array{body: string, code: int}|WP_Error
     */
    public function post_json(string $url, array $data, array $headers = [])
    {
        $payload = wp_json_encode($data);

        if ($this->log_requests) {
            NG_Auth_Log_Logger::info('HTTP POST JSON', [
                'url' => $url,
                'payload' => $payload,
                'headers' => array_keys($headers),
            ]);
        }

        $response = wp_remote_post($url, [
            'timeout' => $this->timeout,
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            'body' => $payload,
        ]);

        return $this->parse_response($url, 'POST', $response);
    }

    /**
     * POST-запрос с form-urlencoded-телом.
     *
     * @param string $url     URL запроса.
     * @param array  $data    Данные для form-urlencoded.
     * @param array  $headers Дополнительные HTTP-заголовки.
     * @return array{body: string, code: int}|WP_Error
     */
    public function post_form(string $url, array $data, array $headers = [])
    {
        $payload = http_build_query($data);

        if ($this->log_requests) {
            NG_Auth_Log_Logger::info('HTTP POST form', [
                'url' => $url,
                'payload' => $payload,
            ]);
        }

        $response = wp_remote_post($url, [
            'timeout' => $this->timeout,
            'headers' => array_merge(['Content-Type' => 'application/x-www-form-urlencoded'], $headers),
            'body' => $payload,
        ]);

        return $this->parse_response($url, 'POST', $response);
    }

    /**
     * Разбор ответа от wp_remote_*.
     *
     * Логирует только при $this->log_requests = true.
     *
     * @param string                        $url      URL запроса.
     * @param string                        $method   HTTP-метод.
     * @param array|WP_Error                $response Ответ wp_remote_*.
     * @return array{body: string, code: int}|WP_Error
     */
    private function parse_response(string $url, string $method, $response)
    {
        if (is_wp_error($response)) {
            // Ошибки соединения логируем всегда — это критично для диагностики.
            NG_Auth_Log_Logger::error('HTTP request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($this->log_requests) {
            NG_Auth_Log_Logger::info('HTTP response', [
                'method' => $method,
                'url' => $url,
                'code' => $code,
                'body' => substr($body, 0, 500),
            ]);
        }

        if ($code >= 400) {
            // HTTP-ошибки (4xx, 5xx) логируем всегда для диагностики.
            NG_Auth_Log_Logger::warning('HTTP error response', [
                'method' => $method,
                'url' => $url,
                'code' => $code,
                'body' => substr($body, 0, 500),
            ]);
        }

        return ['body' => $body, 'code' => $code];
    }
}
