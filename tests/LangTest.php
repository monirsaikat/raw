<?php

// Localization: __(), trans_choice(), locale detection, validation messages
// and the Smarty helpers. Tests use a temporary lang directory so the
// project's own lang/ files never influence the assertions.

$langTestDir = sys_get_temp_dir() . '/comfree-lang-' . getmypid();

before_each(function () use ($langTestDir) {
    foreach (['en', 'de', 'fr'] as $locale) {
        @mkdir($langTestDir . '/' . $locale, 0777, true);
    }

    file_put_contents($langTestDir . '/en/shop.php', '<?php return ' . var_export([
        'total' => 'Total: :amount',
        'hello' => 'Hello, :name!',
        'apples' => 'apple|apples',
        'items' => '{0} No items|{1} One item|[2,9] A few items|[10,*] :count items',
        'nested' => ['deep' => ['key' => 'found']],
    ], true) . ';');
    file_put_contents($langTestDir . '/de/shop.php', '<?php return ' . var_export([
        'total' => 'Summe: :amount',
        'apples' => 'Apfel|Äpfel',
    ], true) . ';');
    file_put_contents($langTestDir . '/en.json', json_encode(['Save changes' => 'Save changes', 'Log in' => 'Log in']));
    file_put_contents($langTestDir . '/de.json', json_encode(['Save changes' => 'Änderungen speichern']));
    file_put_contents($langTestDir . '/en/validation.php', '<?php return ' . var_export([
        'required' => 'Please fill in :attribute.',
        'custom' => ['email' => ['required' => 'We need your email.']],
        'attributes' => ['first_name' => 'first name (given)'],
    ], true) . ';');
    file_put_contents($langTestDir . '/de/validation.php', '<?php return ' . var_export([
        'required' => ':attribute ist erforderlich.',
    ], true) . ';');

    config_set('app.lang_path', $langTestDir);
    config_set('app.locale', 'en');
    config_set('app.fallback_locale', 'en');
    lang_reset();
});

after_each(function () use ($langTestDir) {
    lang_reset();

    foreach (glob($langTestDir . '/*/*') ?: [] as $file) {
        @unlink($file);
    }

    foreach (glob($langTestDir . '/*') ?: [] as $entry) {
        is_dir($entry) ? @rmdir($entry) : @unlink($entry);
    }

    @rmdir($langTestDir);
});

test('__() reads group files, JSON files, nested keys and returns the key when missing', function () {
    assert_same('Total: 5', __('shop.total', ['amount' => 5]));
    assert_same('found', __('shop.nested.deep.key'));
    assert_same('Save changes', __('Save changes'));
    assert_same('shop.nope', __('shop.nope'));
    assert_same('Plain sentence', __('Plain sentence'));
    assert_same('shop.nested', __('shop.nested'), 'an array key is not a line');
    assert_true(lang_has('shop.total'));
    assert_false(lang_has('shop.nope'));
    assert_same('Total: 5', trans('shop.total', ['amount' => 5]));
});

test('placeholders support :name, :Name and :NAME', function () {
    assert_same('Hello, ann!', __('shop.hello', ['name' => 'ann']));
    assert_same('Hello, ANN!', lang_replace('Hello, :NAME!', ['name' => 'ann']));
    assert_same('Hello, Ann!', lang_replace('Hello, :Name!', ['name' => 'ann']));
    assert_same('ab', lang_replace(':a:ab', ['a' => '', 'ab' => 'ab']), 'longer placeholders replace first');
});

test('another locale falls back to the fallback locale, then the key', function () {
    set_locale('de');

    assert_same('de', app_locale());
    assert_true(locale_is('de', 'fr'));
    assert_same('Summe: 9', __('shop.total', ['amount' => 9]));
    assert_same('Hello, Bo!', __('shop.hello', ['name' => 'Bo']), 'missing in de → en');
    assert_same('Änderungen speichern', __('Save changes'));
    assert_same('Log in', __('Log in'));
    assert_same('Total: 1', __('shop.total', ['amount' => 1], 'en'), 'explicit locale');

    config_set('app.fallback_locale', 'fr');
    lang_reset();
    set_locale('de');
    assert_same('shop.hello', __('shop.hello'), 'fallback without the key returns the key');
});

test('trans_choice picks plural forms and ranges', function () {
    assert_same('apple', trans_choice('shop.apples', 1));
    assert_same('apples', trans_choice('shop.apples', 0));
    assert_same('apples', trans_choice('shop.apples', 3));
    assert_same('No items', trans_choice('shop.items', 0));
    assert_same('One item', trans_choice('shop.items', 1));
    assert_same('A few items', trans_choice('shop.items', 5));
    assert_same('12 items', trans_choice('shop.items', 12));
    assert_same('12 items', trans_choice('shop.items', range(1, 12)), 'countables use their count');
    assert_same('Äpfel', trans_choice('shop.apples', 2, [], 'de'));
    assert_same('shop.missing', trans_choice('shop.missing', 2));
});

test('lang_available(), lang_exists() and lang_add()', function () {
    assert_same(['de', 'en', 'fr'], lang_available());
    assert_true(lang_exists('de'));
    assert_false(lang_exists('xx'));
    assert_false(lang_exists('../etc'));

    lang_add('en', 'shop', ['total' => 'Sum :amount', 'extra' => 'Extra']);
    assert_same('Sum 2', __('shop.total', ['amount' => 2]));
    assert_same('Extra', __('shop.extra'));
    assert_same('Hello, X!', __('shop.hello', ['name' => 'X']), 'other lines survive');
});

test('validation messages come from lang/<locale>/validation.php', function () {
    $errors = validate(['first_name' => '', 'email' => '', 'age' => ''], [
        'first_name' => 'required',
        'email' => 'required',
        'age' => 'required',
    ]);

    assert_same('Please fill in first name (given).', $errors['first_name'][0], 'attributes rename the field');
    assert_same('We need your email.', $errors['email'][0], 'custom.<field>.<rule> wins');
    assert_same('Please fill in age.', $errors['age'][0]);

    $errors = validate(['age' => ''], ['age' => 'required'], ['age.required' => 'Age please.']);
    assert_same('Age please.', $errors['age'][0], 'messages passed to validate() win over lang');

    assert_same('The age must be an integer.', validate(['age' => 'x'], ['age' => 'integer'])['age'][0], 'unlisted rules keep the built-in text');

    set_locale('de');
    assert_same('age ist erforderlich.', validate(['age' => ''], ['age' => 'required'])['age'][0]);
    assert_same('The age must be an integer.', validate(['age' => 'x'], ['age' => 'integer'])['age'][0], 'de → en → built-in');
});

test('the locale middleware honours ?lang=, the session and Accept-Language', function () {
    get('/', fn () => app_locale(), 'home', ['locale']);

    assert_same('en', http_get('/')->body());
    assert_same('de', http_get('/', ['Accept-Language' => 'fr-CH;q=0.5, de;q=0.9, en;q=0.1'])->body());
    assert_same('fr', http_get('/', ['Accept-Language' => 'fr-CH,fr;q=0.8'])->body(), 'fr-CH → fr');
    assert_same('en', http_get('/', ['Accept-Language' => 'xx, zz'])->body(), 'unknown tags → default');

    lang_reset();
    assert_same('de', http_get('/?lang=de')->body());

    lang_reset();
    test_next_request();
    assert_same('de', http_get('/')->body(), 'the choice is remembered in the session');
    assert_same('de', app_locale());

    lang_reset();
    assert_same('de', http_get('/?lang=nope')->body(), 'unknown locales are ignored');

    lang_reset();
    assert_same('fr', http_get('/?lang=fr', ['Accept-Language' => 'de'])->body(), '?lang= beats the header');
});

test('templates translate with the __ modifier and the {t} tag', function () {
    lang_add('en', 'shop', ['welcome' => 'Welcome, :name']);
    $dir = sys_get_temp_dir() . '/comfree-lang-views-' . getmypid();
    @mkdir($dir, 0777, true);
    file_put_contents($dir . '/lang.tpl', "{'shop.total'|__:['amount' => 7]}|{t key='shop.welcome' name=\$who}|{t key='shop.items' count=\$n}|{'shop.apples'|trans_choice:2}|{'Save changes'|__}");

    config_set('view.paths', $dir);
    View::reset();

    try {
        $out = view('lang', ['who' => 'Ann <b>', 'n' => 4]);
        assert_same('Total: 7|Welcome, Ann &lt;b&gt;|A few items|apples|Save changes', $out);

        set_locale('de');
        assert_same('Summe: 7|Welcome, Ann &lt;b&gt;|A few items|Äpfel|Änderungen speichern', view('lang', ['who' => 'Ann <b>', 'n' => 4]));
    } finally {
        View::reset();
        @unlink($dir . '/lang.tpl');
        @rmdir($dir);
    }
});

test('paginator labels are translated', function () {
    lang_add('en', 'pagination', ['previous' => 'Back', 'next' => 'Forward']);
    $paginator = new Paginator([1, 2], 30, 2, 2, ['path' => '/list']);

    assert_contains('aria-label="Back"', $paginator->links());
    assert_contains('aria-label="Forward"', $paginator->links());
});
