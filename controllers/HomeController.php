<?php

// The starter page. Replace it with your own controllers
// (`php console.php make:controller Post`) and delete views/home.tpl.

class HomeController
{
    public function index(): string
    {
        return view('home', [
            'php_version' => PHP_VERSION,
            'framework_version' => trim((string) @file_get_contents(BASE_PATH . '/VERSION')),
            'locales' => lang_available(),
        ]);
    }
}
