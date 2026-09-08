<?php

use Smarty\Smarty;

// Smarty wrapper. Templates live in views/ (view('auth/login') renders
// views/auth/login.tpl) and every render receives a few globals: app_name,
// auth_user, errors, old, flash and current_route. Output is HTML-escaped by
// default; use {$html|raw} for trusted markup. Settings: config/view.php.

class View
{
    private static ?View $instance = null;
    private static array $shared = [];

    public static function instance(): self
    {
        return self::$instance ??= new self(self::createSmarty());
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    // Makes a value available to every template for the rest of the request.
    public static function share(string $key, $value): void
    {
        self::$shared[$key] = $value;
    }

    public function __construct(private Smarty $smarty)
    {
    }

    public function smarty(): Smarty
    {
        return $this->smarty;
    }

    public function render(string $template, array $data = []): string
    {
        $tpl = $this->smarty->createTemplate(self::normalize($template));
        $tpl->assign(array_merge($this->globals(), self::$shared, $data));

        return $tpl->fetch();
    }

    public function exists(string $template): bool
    {
        return $this->smarty->templateExists(self::normalize($template));
    }

    // 'home' / 'views/home' / 'home.tpl' → 'home.tpl'
    public static function normalize(string $template): string
    {
        $template = preg_replace('#^views/#', '', ltrim($template, '/'));

        return str_ends_with($template, '.tpl') ? $template : $template . '.tpl';
    }

    private function globals(): array
    {
        return [
            'app_name' => config('app.name', 'App'),
            'auth_user' => auth_user(),
            'errors' => flash('errors') ?? [],
            'old' => flash('old') ?? [],
            'flash' => flash_all(),
            'current_route' => current_route_name(),
            'app_debug' => APP_DEBUG,
        ];
    }

    private static function createSmarty(): Smarty
    {
        require_once BASE_PATH . '/libs/Smarty/libs/Smarty.class.php';

        $config = (array) config('view', []);

        $smarty = new Smarty();
        $smarty->setTemplateDir($config['paths'] ?? BASE_PATH . '/views');
        $smarty->setCompileDir($config['compiled'] ?? BASE_PATH . '/storage/views');
        $smarty->setCacheDir($config['cache'] ?? BASE_PATH . '/storage/cache/smarty');
        $smarty->setEscapeHtml((bool) ($config['escape_html'] ?? true));
        $smarty->muteUndefinedOrNullWarnings();

        // Skips a filemtime() check per template on every request in
        // production. Run `php console.php view:clear` after editing .tpl files.
        if (!($config['compile_check'] ?? APP_DEBUG)) {
            $smarty->setCompileCheck(Smarty::COMPILECHECK_OFF);
        }

        foreach ((array) ($config['functions'] ?? []) as $name => $callable) {
            $smarty->registerPlugin('function', $name, $callable);
        }

        foreach ((array) ($config['modifiers'] ?? []) as $name => $callable) {
            $smarty->registerPlugin('modifier', $name, $callable);
        }

        return $smarty;
    }
}
