<?php

// Mail commands: make:mail and mail:test. Loaded by console.php.

command('make:mail', 'Create a mailable class in mail/ with its template [name, e.g. OrderShipped]', function (array $args) {
    [$positional] = parse_arguments($args);
    $name = str_studly(trim((string) ($positional[0] ?? '')));

    if ($name === '') {
        error_line('Usage: php console.php make:mail OrderShipped');

        return 1;
    }

    // OrderShippedMail → views/mail/order_shipped.tpl
    $template = str_snake(preg_replace('/Mail$/', '', $name) ?: $name);
    $subject = ucfirst(str_replace('_', ' ', $template));

    $ok = write_stub(BASE_PATH . '/mail/' . $name . '.php', <<<PHP
        <?php

        // Send with: Mail::to(\$address)->send(new $name());
        // Queue with: Mail::queue((new $name())->to(\$address));

        class $name extends Mailable
        {
            public function __construct()
            {
            }

            public function build(): void
            {
                \$this->subject('$subject')
                    ->view('mail/$template', [
                        //
                    ]);
            }
        }

        PHP);

    $ok = write_stub(BASE_PATH . '/views/mail/' . $template . '.tpl', <<<TPL
        {extends file='mail/layout.tpl'}

        {block name='content'}
            <h1 style="margin:0 0 16px;font-size:22px;">$subject</h1>
            <p style="margin:0;">Hello from {\$app_name}.</p>
        {/block}

        TPL) && $ok;

    return $ok ? 0 : 1;
});

command('mail:test', 'Send a test message through the configured mailer [address] [--mailer=smtp]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $address = trim((string) ($positional[0] ?? ''));

    if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
        error_line('Usage: php console.php mail:test you@example.com [--mailer=smtp]');

        return 1;
    }

    $name = (string) ($options['mailer'] ?? config('mail.default', 'log'));
    $config = (array) config('mail.mailers.' . $name, []);
    $started = microtime(true);

    line("Sending a test message to $address through mailer [$name] ...");

    try {
        $transport = mailer($name);
        $message = Mail::send(
            (new Mailable())
                ->to($address)
                ->subject('[' . app_name() . '] Test message')
                ->text("This is a test message from " . app_name() . " sent at " . date('c') . ".\n\nIf you can read this, mail delivery works.")
                ->html('<p>This is a test message from <strong>' . e(app_name()) . '</strong> sent at ' . date('c') . '.</p><p>If you can read this, mail delivery works.</p>'),
            $name
        );
    } catch (Throwable $e) {
        error_line('Failed: ' . $e->getMessage());

        return 1;
    }

    $elapsed = round((microtime(true) - $started) * 1000);

    print_table(['Field', 'Value'], [
        ['Mailer', $name . ' (' . get_class($transport) . ')'],
        ['Host', ($config['host'] ?? '-') . (isset($config['port']) ? ':' . $config['port'] : '')],
        ['Encryption', (string) ($config['encryption'] ?? '-')],
        ['From', MimeBuilder::formatAddresses([$message->from])],
        ['To', $address],
        ['Subject', $message->subject],
        ['Time', $elapsed . ' ms'],
    ]);

    line(match ($name) {
        'log' => 'Written to ' . relative_path(log_path()) . '/app-' . date('Y-m-d') . '.log (MAIL_MAILER=log).',
        'array' => 'Kept in memory only (MAIL_MAILER=array).',
        default => 'Sent.',
    });

    return 0;
});
