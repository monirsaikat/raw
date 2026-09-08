<?php

// Autoloader for the framework's own class directories. "Foo\Bar" maps to
// <dir>/Foo/Bar.php and the first directory that has the file wins. Smarty
// ships its own loader. Function-only files are required by bootstrap.php.

spl_autoload_register(function (string $class): void {
    $relative = str_replace('\\', '/', ltrim($class, '\\')) . '.php';

    $directories = [
        BASE_PATH . '/core',
        BASE_PATH . '/controllers',
        BASE_PATH . '/models',
        BASE_PATH . '/services',
        BASE_PATH . '/policies',
        BASE_PATH . '/jobs',
        BASE_PATH . '/listeners',
        BASE_PATH . '/mail',
    ];

    foreach ($directories as $directory) {
        $file = $directory . '/' . $relative;

        if (is_file($file)) {
            require_once $file;

            return;
        }
    }
});
