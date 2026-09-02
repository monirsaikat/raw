<?php

function view(string $template, array $data = [])
{
    static $view;

    if (!$view) {
        $smarty = require __DIR__ . '/../config/smarty.php';
        $view = new View($smarty);
    }

    return $view->render($template, $data);
}

function asset($path)
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

    return $base . '/' . ltrim($path, '/');
}