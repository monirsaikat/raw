<?php

// PSR-3-style file logger writing to storage/logs/app-YYYY-MM-DD.log.
// Messages may use {placeholders} filled from $context; the remaining
// context is appended as JSON. Threshold comes from config('app.log_level').

function log_path(): string
{
    return BASE_PATH . '/storage/logs';
}

function log_message(string $level, string $message, array $context = []): void
{
    static $levels = [
        'debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3,
        'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7,
    ];

    $level = strtolower($level);
    $threshold = strtolower((string) config('app.log_level', 'debug'));

    if (($levels[$level] ?? 0) < ($levels[$threshold] ?? 0)) {
        return;
    }

    $replacements = [];

    foreach ($context as $key => $value) {
        if ($value instanceof Throwable) {
            $context[$key] = get_class($value) . ': ' . $value->getMessage() . ' in ' . $value->getFile() . ':' . $value->getLine();
        } elseif (is_scalar($value) || $value === null || $value instanceof Stringable) {
            $replacements['{' . $key . '}'] = (string) $value;
        }
    }

    $message = strtr($message, $replacements);

    $suffix = $context === []
        ? ''
        : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    $line = sprintf(
        "[%s] %s.%s: %s%s\n",
        date('Y-m-d H:i:s'),
        config('app.env', 'production'),
        strtoupper($level),
        $message,
        $suffix
    );

    $directory = log_path();

    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        error_log(rtrim($line));

        return;
    }

    @file_put_contents($directory . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function log_exception(Throwable $e, string $level = 'error'): void
{
    log_message($level, '{class}: {message} in {file}:{line}', [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
}

function log_debug(string $message, array $context = []): void
{
    log_message('debug', $message, $context);
}

function log_info(string $message, array $context = []): void
{
    log_message('info', $message, $context);
}

function log_notice(string $message, array $context = []): void
{
    log_message('notice', $message, $context);
}

function log_warning(string $message, array $context = []): void
{
    log_message('warning', $message, $context);
}

function log_error(string $message, array $context = []): void
{
    log_message('error', $message, $context);
}

function log_critical(string $message, array $context = []): void
{
    log_message('critical', $message, $context);
}
