<?php

spl_autoload_register(function ($class) {

    static $directories = [
        __DIR__ . '/../controllers',
        __DIR__ . '/../models',
        __DIR__ . '/../services',
    ];

    $relative = str_replace('\\', '/', $class) . '.php';

    foreach ($directories as $directory) {
        $file = $directory . '/' . $relative;

        if (is_file($file)) {
            require_once $file;

            return;
        }
    }
});