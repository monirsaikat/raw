<?php

require_once __DIR__ . '/../libs/Smarty/libs/Smarty.class.php';

use Smarty\Smarty;

$smarty = new Smarty();

$smarty->setTemplateDir(__DIR__ . '/../templates');
$smarty->setCompileDir(__DIR__ . '/../templates_c');
$smarty->setCacheDir(__DIR__ . '/../cache');
$smarty->registerPlugin('function', 'navigate', 'navigate');
$smarty->registerPlugin('function', 'asset', 'asset');

return $smarty;