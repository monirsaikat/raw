<?php

// Debug toolbar helpers (see core/Toolbar.php). send_response() calls
// toolbar_inject() on every response; it returns immediately unless
// APP_DEBUG is on, config('app.toolbar') is true and the response is an
// HTML page. Append ?_toolbar=0 to a URL to hide it for one request.

function toolbar_inject(Response $response): void
{
    Toolbar::inject($response);
}

// Hide the toolbar for the rest of the request (e.g. in an action that
// renders a printable page).
function toolbar_disable(): void
{
    Toolbar::disable();
}

// Re-enable after toolbar_disable(); $force also allows it under the CLI
// SAPI, which the test suite uses.
function toolbar_enable(bool $force = false): void
{
    Toolbar::enable($force);
}

function toolbar_collect(Response $response): array
{
    return Toolbar::collect($response);
}

function toolbar_render(array $data): string
{
    return Toolbar::render($data);
}
