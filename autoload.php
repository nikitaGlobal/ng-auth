<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Автозагрузчик классов плагина NG Auth.
 *
 * Регистрирует функцию автозагрузки через spl_autoload_register.
 * Преобразует имена классов вида NG_Auth_* в пути к файлам в src/.
 *
 * @package NG_Auth
 * @since   1.0.0
 */

/**
 * Функция автозагрузки классов плагина.
 *
 * Преобразует имя класса NG_Auth_Path_To_Class в путь src/Path/To/Class.php
 * и подключает файл, если он существует.
 *
 * @param string $class Полное имя класса с учётом пространства имён.
 * @return void
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'NG_Auth_';
    $prefix_len = strlen($prefix);

    if (strncmp($prefix, $class, $prefix_len) !== 0) {
        return;
    }

    $relative_class = substr($class, $prefix_len);
    $path_parts = explode('_', $relative_class);

    $filename = array_pop($path_parts) . '.php';
    $dir = implode('/', $path_parts);
    $file = NG_AUTH_DIR . 'src/' . ($dir ? $dir . '/' : '') . $filename;

    if (file_exists($file)) {
        require_once $file;
    }
});
