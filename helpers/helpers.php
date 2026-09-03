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

function current_year()
{
    return date('Y');
}

function asset($path)
{
    // Smarty function plugins pass an attribute array ({asset path='x'}); a
    // plain string is accepted too so this also works called from PHP.
    if (is_array($path)) {
        $path = $path['path'] ?? '';
    }

    return base_path() . '/' . ltrim($path, '/');
}