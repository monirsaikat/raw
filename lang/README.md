# lang/

One directory per locale (`en/`, `bn/`, `de/`, …) holding PHP files that
return arrays, plus optional `<locale>.json` files for flat sentence keys.

    __('messages.welcome', ['app' => app_name()])   → lang/en/messages.php['welcome']
    __('Save changes')                               → lang/en.json["Save changes"], or the key itself
    trans_choice('messages.items', 3)                → "3 items"

`php console.php make:lang bn` copies the `en` files to start a locale;
`php console.php lang:missing bn` lists keys that `en` has and `bn` lacks.
