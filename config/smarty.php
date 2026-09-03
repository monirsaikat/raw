<?php

require_once __DIR__ . '/../libs/Smarty/libs/Smarty.class.php';

use Smarty\Smarty;

$smarty = new Smarty();

$smarty->setTemplateDir(__DIR__ . '/../templates');
$smarty->setCompileDir(__DIR__ . '/../templates_c');
$smarty->setCacheDir(__DIR__ . '/../cache');
$smarty->registerPlugin('function', 'navigate', 'navigate');
$smarty->registerPlugin('function', 'asset', 'asset');
$smarty->registerPlugin('function', 'csrf_field', 'csrf_field');
$smarty->registerPlugin('function', 'method_field', 'method_field');

// Auto-escapes {$var} output so views are XSS-safe by default.
// Use {$var|raw} for the rare case where trusted HTML must pass through.
$smarty->setEscapeHtml(true);

if (!defined('APP_DEBUG') || !APP_DEBUG) {
    // Skips per-request filemtime() stats on every template; wipe
    // templates_c manually after editing .tpl files in production.
    $smarty->setCompileCheck(\Smarty\Smarty::COMPILECHECK_OFF);
}

return $smarty;