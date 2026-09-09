<?php

// Localization. Strings live in lang/<locale>/<group>.php (arrays, looked up
// with dotted keys: __('auth.failed')) or lang/<locale>.json (flat, the key
// is the English sentence: __('Welcome back')). A missing key falls back to
// the fallback locale, then to the key itself, so untranslated apps keep
// working. Templates: {'auth.failed'|__}, {t key='messages.hello' name=$name},
// {'messages.apples'|trans_choice:$count}.

$GLOBALS['langLines'] = [];
$GLOBALS['langLocale'] = null;

function lang_path(?string $locale = null): string
{
    $base = (string) config('app.lang_path', BASE_PATH . '/lang');

    return $locale === null ? $base : $base . '/' . $locale;
}

function fallback_locale(): string
{
    return (string) config('app.fallback_locale', 'en');
}

// Current locale: set_locale(), else the session (kept by the 'locale'
// middleware), else config('app.locale').
function app_locale(): string
{
    global $langLocale;

    if ($langLocale !== null) {
        return $langLocale;
    }

    if (session_readable() && is_string($stored = session_get('_locale')) && lang_exists($stored)) {
        return $langLocale = $stored;
    }

    return $langLocale = (string) config('app.locale', 'en');
}

function set_locale(string $locale, bool $remember = false): void
{
    global $langLocale;

    $langLocale = $locale;

    if ($remember) {
        session_set('_locale', $locale);
    }
}

function locale_is(string ...$locales): bool
{
    return in_array(app_locale(), $locales, true);
}

// Locales that have a directory or a JSON file in lang/.
function lang_available(): array
{
    $locales = [];

    foreach (glob(lang_path() . '/*') ?: [] as $entry) {
        $name = basename($entry);

        if (is_dir($entry)) {
            $locales[] = $name;
        } elseif (str_ends_with($name, '.json')) {
            $locales[] = substr($name, 0, -5);
        }
    }

    $locales = array_values(array_unique($locales));
    sort($locales);

    return $locales;
}

function lang_exists(string $locale): bool
{
    return preg_match('/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/', $locale) === 1
        && (is_dir(lang_path($locale)) || is_file(lang_path() . '/' . $locale . '.json'));
}

// Lines of one group ('*' is the JSON file) for a locale, loaded once.
function lang_lines(string $locale, string $group): array
{
    global $langLines;

    if (isset($langLines[$locale][$group])) {
        return $langLines[$locale][$group];
    }

    $lines = [];

    if ($group === '*') {
        $file = lang_path() . '/' . $locale . '.json';

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (!is_array($decoded)) {
                throw new RuntimeException("Translation file [$file] is not valid JSON.");
            }

            $lines = $decoded;
        }
    } else {
        $file = lang_path($locale) . '/' . $group . '.php';

        if (is_file($file)) {
            $loaded = require $file;
            $lines = is_array($loaded) ? $loaded : [];
        }
    }

    return $langLines[$locale][$group] = $lines;
}

// Register lines at runtime (packages, tests): lang_add('en', 'shop', ['total' => 'Total']).
function lang_add(string $locale, string $group, array $lines): void
{
    global $langLines;

    $langLines[$locale][$group] = array_replace_recursive(lang_lines($locale, $group), $lines);
}

function lang_reset(): void
{
    global $langLines, $langLocale;

    $langLines = [];
    $langLocale = null;
}

// The raw line for a key in one locale, or null. 'auth.failed' reads
// lang/<locale>/auth.php['failed']; 'validation.custom.email.required'
// walks nested arrays. Keys without a dot (or whose group has no file)
// are looked up in <locale>.json.
function lang_line(string $key, string $locale): string|array|null
{
    if (str_contains($key, '.')) {
        [$group, $item] = explode('.', $key, 2);
        $lines = lang_lines($locale, $group);

        if ($lines !== []) {
            $value = $lines;

            foreach (explode('.', $item) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }

                $value = $value[$segment];
            }

            if ($value !== null) {
                return $value;
            }
        }
    }

    $json = lang_lines($locale, '*');

    return array_key_exists($key, $json) ? $json[$key] : null;
}

function lang_has(string $key, ?string $locale = null): bool
{
    return lang_line($key, $locale ?? app_locale()) !== null;
}

// :name placeholders; :Name and :NAME change the case of the replacement.
function lang_replace(string $line, array $replace): string
{
    if ($replace === []) {
        return $line;
    }

    uksort($replace, fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

    $map = [];

    foreach ($replace as $key => $value) {
        $value = (string) $value;
        $map[':' . $key] = $value;
        $map[':' . mb_strtoupper((string) $key)] = mb_strtoupper($value);
        $map[':' . ucfirst((string) $key)] = ucfirst($value);
    }

    return strtr($line, $map);
}

// __('auth.failed'), __('Hello :name', ['name' => 'Ann']), __('shop.total', [], 'de').
// Also a Smarty modifier: {'auth.failed'|__} and {'Hello :name'|__:['name' => $n]}.
function __(string $key, array $replace = [], ?string $locale = null): string
{
    $locale ??= app_locale();
    $line = lang_line($key, $locale);

    if ($line === null && $locale !== fallback_locale()) {
        $line = lang_line($key, fallback_locale());
    }

    if (!is_string($line)) {
        $line = $key;
    }

    return lang_replace($line, $replace);
}

function trans(string $key, array $replace = [], ?string $locale = null): string
{
    return __($key, $replace, $locale);
}

// Pluralisation. Lines: 'apple|apples', or explicit ranges
// '{0} none|[1,19] some|[20,*] many'. The :count placeholder is filled in.
function trans_choice(string $key, int|float|Countable|array $number, array $replace = [], ?string $locale = null): string
{
    $count = is_countable($number) ? count($number) : $number;
    $line = __($key, [], $locale);

    return lang_replace(lang_choose($line, $count), ['count' => $count] + $replace);
}

function lang_choose(string $line, int|float $count): string
{
    $segments = array_map('trim', explode('|', $line));
    $plain = [];

    foreach ($segments as $segment) {
        if (preg_match('/^\{\s*([^}]+)\s*\}\s*(.*)$/s', $segment, $m)) {
            if (is_numeric($m[1]) && (float) $m[1] == $count) {
                return $m[2];
            }

            continue;
        }

        if (preg_match('/^\[\s*([^,\]]+)\s*,\s*([^\]]+)\s*\]\s*(.*)$/s', $segment, $m)) {
            $from = trim($m[1]);
            $to = trim($m[2]);
            $low = $from === '*' ? -INF : (float) $from;
            $high = $to === '*' ? INF : (float) $to;

            if ($count >= $low && $count <= $high) {
                return $m[3];
            }

            continue;
        }

        $plain[] = $segment;
    }

    if ($plain === []) {
        return $segments[0] ?? $line;
    }

    if (count($plain) === 1) {
        return $plain[0];
    }

    return $count == 1 ? $plain[0] : $plain[1];
}

// Best locale for the request, out of the ones in lang/: ?lang= or a
// custom parameter, then the session, then Accept-Language.
function lang_detect(string $parameter = 'lang'): ?string
{
    $available = lang_available();
    $default = (string) config('app.locale', 'en');

    if ($available === []) {
        return $default;
    }

    $requested = query($parameter);

    if (is_string($requested) && in_array($requested, $available, true)) {
        return $requested;
    }

    if (session_readable() && is_string($stored = session_get('_locale')) && in_array($stored, $available, true)) {
        return $stored;
    }

    foreach (lang_accepted() as $tag) {
        if (in_array($tag, $available, true)) {
            return $tag;
        }

        $short = substr($tag, 0, 2);

        if (in_array($short, $available, true)) {
            return $short;
        }
    }

    return $default;
}

// Accept-Language tags ordered by quality: ['en-GB', 'en', 'fr'].
function lang_accepted(): array
{
    $header = (string) request_header('Accept-Language', '');
    $tags = [];

    foreach (explode(',', $header) as $index => $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        [$tag, $q] = array_pad(explode(';q=', $part, 2), 2, '1');

        if (!preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $tag)) {
            continue;
        }

        $tags[] = ['tag' => str_replace('-', '_', $tag), 'q' => (float) $q, 'i' => $index];
    }

    usort($tags, fn ($a, $b) => $b['q'] <=> $a['q'] ?: $a['i'] <=> $b['i']);

    return array_map(fn ($t) => $t['tag'], $tags);
}

// Picks the locale for the request and remembers a ?lang= choice in the
// session. Runs on every route by default (see global_middleware()).
middleware('locale', function (callable $next, string $parameter = 'lang') {
    $locale = lang_detect($parameter);

    if ($locale !== null) {
        set_locale($locale, remember: query($parameter) === $locale);
    }

    return $next();
});

add_global_middleware('locale');

// Smarty tag: {t key='messages.greeting' name=$user.name} — every other
// attribute is a placeholder. {t key='messages.items' count=$n} pluralises.
// Output is HTML-escaped; add raw=true for lines that contain markup.
function lang_tag(array $params): string
{
    $key = (string) ($params['key'] ?? '');
    $locale = isset($params['locale']) ? (string) $params['locale'] : null;
    $raw = !empty($params['raw']);
    unset($params['key'], $params['locale'], $params['raw']);

    if (array_key_exists('count', $params)) {
        $count = $params['count'];
        unset($params['count']);

        $line = trans_choice($key, is_countable($count) ? count($count) : (int) $count, $params, $locale);
    } else {
        $line = __($key, $params, $locale);
    }

    return $raw ? $line : htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
