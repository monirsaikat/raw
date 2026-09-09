<?php

// Application strings. Read with __('messages.welcome', ['app' => app_name()])
// or, in templates, {t key='messages.welcome' app=$app_name} and
// {'messages.welcome'|__:['app' => $app_name]}. The starter page
// (views/home.tpl) and layout use the keys below; replace them with yours.

return [
    'welcome' => 'Welcome to :app',
    'eyebrow' => 'Your new application is running',
    'tagline' => 'A small, dependency-free PHP framework: routing, models, migrations, queues, mail, auth and tests, with nothing to install. This page is views/home.tpl, served by routes/web.php.',
    'read_docs' => 'Read the docs',
    'next_steps' => 'Next steps',
    'locale' => 'Locale',
    'nav_home' => 'Home',
    'nav_docs' => 'Docs',
    'footer_built_with' => 'Built with ComfreePHP',

    'step_env_title' => 'Configure .env',
    'step_env_body' => 'Copy .env.example to .env, set APP_NAME and the DB_* values, then generate the application key.',
    'step_db_title' => 'Create the database tables',
    'step_db_body' => 'The users table ships as a migration; add your own with make:migration and run them.',
    'step_crud_title' => 'Scaffold your first resource',
    'step_crud_body' => 'One command writes the model, migration, factory, policy, controller, views, a test and the routes.',
    'step_test_title' => 'Run the tests',
    'step_test_body' => 'Framework and app tests run on an in-memory SQLite database in a few seconds.',

    'feature_fast_title' => 'Fast by default',
    'feature_fast_body' => 'No container compilation, no Composer autoload, a static route table and cached templates. Boot is a few hundred microseconds.',
    'feature_batteries_title' => 'Batteries included',
    'feature_batteries_body' => 'Query builder, models with relations, validation, sessions, CSRF, throttling, events, queues, mail, encryption and a debug toolbar.',
    'feature_lang_title' => 'Speaks your language',
    'feature_lang_body' => 'Strings live in lang/. Add a locale with make:lang, switch with ?lang= or Accept-Language.',

    // Example plural line: trans_choice('messages.items', $count)
    'items' => '{0} No items|{1} One item|[2,*] :count items',
];
