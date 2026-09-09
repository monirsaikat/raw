<?php

// Localization commands: make:lang, lang:missing, lang:list. Loaded by console.php.

// Every key of a locale as "group.dotted.key" => line. JSON lines keep
// their sentence key. Nested arrays flatten with dots.
function lang_flatten(string $locale): array
{
    $flat = [];

    $walk = function (array $lines, string $prefix) use (&$walk, &$flat): void {
        foreach ($lines as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $walk($value, $full);
            } else {
                $flat[$full] = $value;
            }
        }
    };

    foreach (glob(lang_path($locale) . '/*.php') ?: [] as $file) {
        $walk(lang_lines($locale, basename($file, '.php')), basename($file, '.php'));
    }

    foreach (lang_lines($locale, '*') as $key => $value) {
        $flat[$key] = $value;
    }

    return $flat;
}

command('make:lang', 'Start a locale by copying the fallback locale files [locale, e.g. bn] [--from=en]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $locale = trim((string) ($positional[0] ?? ''));
    $from = (string) ($options['from'] ?? fallback_locale());

    if (!preg_match('/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/', $locale)) {
        error_line('Usage: php console.php make:lang bn [--from=en]');

        return 1;
    }

    if (!is_dir(lang_path($from)) && !is_file(lang_path() . "/$from.json")) {
        error_line("Source locale [$from] has no files in lang/.");

        return 1;
    }

    $created = 0;

    foreach (glob(lang_path($from) . '/*.php') ?: [] as $file) {
        $created += write_stub(lang_path($locale) . '/' . basename($file), (string) file_get_contents($file)) ? 1 : 0;
    }

    if (is_file(lang_path() . "/$from.json")) {
        $created += write_stub(lang_path() . "/$locale.json", (string) file_get_contents(lang_path() . "/$from.json")) ? 1 : 0;
    }

    line("Locale [$locale] ready: $created file(s) copied from [$from]. Translate the values, keep the keys.");
    line("Switch with ?lang=$locale, set_locale('$locale') or APP_LOCALE=$locale.");

    return 0;
});

command('lang:missing', 'Keys the fallback locale has that another locale lacks [locale] [--from=en]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $locale = trim((string) ($positional[0] ?? ''));
    $from = (string) ($options['from'] ?? fallback_locale());

    if ($locale === '') {
        error_line('Usage: php console.php lang:missing bn [--from=en]');

        return 1;
    }

    $source = lang_flatten($from);
    $target = lang_flatten($locale);
    $missing = array_diff_key($source, $target);
    $untranslated = array_filter(array_intersect_key($target, $source), fn ($v, $k) => $v === $source[$k], ARRAY_FILTER_USE_BOTH);

    foreach ($missing as $key => $value) {
        line("missing       $key");
    }

    foreach ($untranslated as $key => $value) {
        line("untranslated  $key");
    }

    line(sprintf('%d missing, %d identical to [%s], %d keys in [%s].', count($missing), count($untranslated), $from, count($target), $locale));

    return $missing === [] ? 0 : 1;
});

command('lang:list', 'Show the locales in lang/ and how many keys each has', function () {
    $locales = lang_available();

    if ($locales === []) {
        line('No locales found in ' . lang_path());

        return 0;
    }

    foreach ($locales as $locale) {
        line(sprintf('%-8s %4d keys%s', $locale, count(lang_flatten($locale)), $locale === fallback_locale() ? '  (fallback)' : ''));
    }

    line('Default: ' . config('app.locale', 'en'));

    return 0;
});
