<?php

define('APP_DEBUG', false);

require_once __DIR__ . '/core/autoload.php';
require_once __DIR__ . '/core/session/session.php';
require_once __DIR__ . '/core/view.php';
require_once __DIR__ . '/helpers/helpers.php';
require_once __DIR__ . '/core/route.php';

require __DIR__ . '/routes/web.php';

route();
