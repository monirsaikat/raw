<?php

// Mail module: mailer() builds the transport named in config/mail.php
// (smtp, sendmail, log, array) and caches it for the process. The Mail
// facade, Mailable base class, Message, MimeBuilder and the transports are
// autoloaded classes in core/. App mailables live in mail/ (make:mail).

$GLOBALS['__mailers'] = [];

// The configured transport, or a named one from config('mail.mailers').
function mailer(?string $name = null): MailTransport
{
    $name ??= (string) config('mail.default', 'log');

    return $GLOBALS['__mailers'][$name] ??= mail_transport($name);
}

// Builds a fresh transport for a mailer name (no caching).
function mail_transport(string $name): MailTransport
{
    $config = (array) config('mail.mailers.' . $name, []);

    if ($config === []) {
        throw new InvalidArgumentException("Mailer [$name] is not configured in config/mail.php.");
    }

    $transport = (string) ($config['transport'] ?? $name);

    // Custom transports: bind the class name in config/container.php.
    if (app()->bound($transport)) {
        $custom = app($transport);

        if ($custom instanceof MailTransport) {
            return $custom;
        }
    }

    return match ($transport) {
        'smtp' => SmtpTransport::fromConfig($config),
        'sendmail' => new SendmailTransport(),
        'log' => new LogTransport(),
        'array' => new ArrayTransport(),
        default => throw new InvalidArgumentException("Unsupported mail transport [$transport] for mailer [$name]."),
    };
}

// Registers a transport instance under a name, e.g. for tests or a custom API driver.
function mail_use(string $name, MailTransport $transport): void
{
    $GLOBALS['__mailers'][$name] = $transport;
}

// Drops cached transports so config changes take effect (tests).
function mail_reset(): void
{
    $GLOBALS['__mailers'] = [];
    Mail::restore();
}
