<?php

// Mail module: Mailable building and rendering, MimeBuilder output, the
// transports that need no network (array, log), Mail facade and Mail::fake().

class MailTestOrderShipped extends Mailable
{
    public function __construct(public string $customer, public int $order)
    {
    }

    public function build(): void
    {
        $this->subject("Order #{$this->order} shipped")
            ->html('<p>Hi ' . e($this->customer) . ', order ' . $this->order . ' is on its way.</p>')
            ->text("Hi {$this->customer}, order {$this->order} is on its way.")
            ->replyTo('support@example.com', 'Support')
            ->header('X-Order-Id', (string) $this->order)
            ->priority(1);
    }
}

before_each(function () {
    mail_reset();
    config_set('mail.default', 'array');
    config_set('mail.from', ['address' => 'noreply@example.com', 'name' => 'Example App']);
    config_set('app.url', 'http://localhost/app');
});

after_each(function () {
    mail_reset();
});

test('Mailable setters, address normalisation and default From', function () {
    $mailable = (new Mailable())
        ->to('a@example.com', 'Ann')
        ->to(['b@example.com', 'c@example.com' => 'Cy'])
        ->cc([['address' => 'd@example.com', 'name' => 'Dee']])
        ->bcc('e@example.com')
        ->subject('Hi')
        ->text('Body');

    $message = $mailable->toMessage();

    assert_same([
        ['address' => 'a@example.com', 'name' => 'Ann'],
        ['address' => 'b@example.com', 'name' => ''],
        ['address' => 'c@example.com', 'name' => 'Cy'],
    ], $message->to);
    assert_same('Dee', $message->cc[0]['name']);
    assert_same(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'e@example.com'], $message->recipients());
    assert_same(['address' => 'noreply@example.com', 'name' => 'Example App'], $message->from);
    assert_true($mailable->hasTo('a@example.com'));
    assert_true($mailable->hasCc('d@example.com'));
    assert_true($mailable->hasBcc('e@example.com'));
    assert_false($mailable->hasTo('zed@example.com'));
    assert_null($message->html);
    assert_same('Body', $message->text);

    assert_throws(fn () => (new Mailable())->to('not-an-email'), InvalidArgumentException::class, 'Invalid email');
    assert_throws(fn () => (new Mailable())->to('x@example.com')->toMessage(), RuntimeException::class, 'no body');
});

test('build() runs once and a view renders through Smarty with the mail layout', function () {
    $mail = new WelcomeMail('Ann <b>');
    $html = $mail->render();
    $mail->render();

    assert_same('Welcome to ' . app_name(), $mail->getSubject());
    assert_same('mail/welcome', $mail->getView());
    assert_contains('Welcome, Ann &lt;b&gt;!', $html, 'template output is escaped');
    assert_contains('http://localhost/app/account', $html);
    assert_contains('<title>Welcome to ' . app_name() . '</title>', $html, 'subject reaches the layout');
    assert_contains('&copy; ' . date('Y'), $html);

    $message = $mail->to('ann@example.com')->toMessage();
    assert_contains('Open your account', (string) $message->html);
    assert_contains('Thanks for joining', (string) $message->text);
});

test('MimeBuilder: multipart/alternative, quoted-printable, RFC 2047 subject and names', function () {
    $message = (new MailTestOrderShipped('Zoë', 42))
        ->to('zoe@example.com', 'Zoë Ünicode')
        ->from('shop@example.com', 'The "Shop"')
        ->toMessage();

    $raw = $message->toString();
    [$headers, $body] = explode("\r\n\r\n", $raw, 2);

    assert_contains('From: "The \"Shop\"" <shop@example.com>', $headers);
    assert_contains('To: =?UTF-8?B?' . base64_encode('Zoë Ünicode') . '?= <zoe@example.com>', $headers);
    assert_contains('Subject: Order #42 shipped', $headers);
    assert_contains('Reply-To: "Support" <support@example.com>', $headers);
    assert_contains('X-Order-Id: 42', $headers);
    assert_contains('X-Priority: 1', $headers);
    assert_contains('MIME-Version: 1.0', $headers);
    assert_matches('/Message-ID: <[0-9a-f]{32}@localhost>/', $headers);
    assert_contains('Content-Type: multipart/alternative; boundary="', $headers);
    assert_not_contains('Bcc:', $headers);

    preg_match('/boundary="([^"]+)"/', $headers, $m);
    assert_contains('--' . $m[1] . "\r\nContent-Type: text/plain; charset=UTF-8", $body);
    assert_contains('Content-Type: text/html; charset=UTF-8', $body);
    assert_contains('Content-Transfer-Encoding: quoted-printable', $body);
    assert_contains('Hi Zo=C3=AB, order 42', $body, 'text part is quoted-printable');
    assert_contains('--' . $m[1] . "--\r\n", $body);

    $unicode = (new Mailable())->to('a@example.com')->subject('Résumé ✓')->text('x')->toMessage();
    assert_contains('Subject: =?UTF-8?B?' . base64_encode('Résumé ✓') . '?=', (new MimeBuilder($unicode))->headers());

    $injected = (new Mailable())->to('a@example.com')->subject("Hi\r\nBcc: evil@example.com")->text('x')->toMessage();
    assert_not_contains("\r\nBcc:", (new MimeBuilder($injected))->headers(), 'newlines are stripped from headers');
});

test('MimeBuilder: attachments produce multipart/mixed with base64 parts', function () {
    $file = sys_get_temp_dir() . '/comfree-mail-test-' . getmypid() . '.txt';
    file_put_contents($file, "line one\n.line two\n");

    try {
        $message = (new Mailable())
            ->to('a@example.com')
            ->subject('Files')
            ->html('<b>see attached</b>')
            ->text('see attached')
            ->attach($file, 'notes.txt', 'text/plain')
            ->attachData('{"ok":true}', 'data.json', 'application/json')
            ->toMessage();
    } finally {
        @unlink($file);
    }

    assert_count(2, $message->attachments);
    assert_same('notes.txt', $message->attachments[0]['name']);
    assert_same("line one\n.line two\n", $message->attachments[0]['content']);

    $raw = $message->toString();
    assert_contains('Content-Type: multipart/mixed; boundary="=_mixed_', $raw);
    assert_contains('Content-Type: multipart/alternative; boundary="=_alt_', $raw);
    assert_contains('Content-Type: text/plain; name="notes.txt"', $raw);
    assert_contains('Content-Disposition: attachment; filename="notes.txt"', $raw);
    assert_contains('Content-Transfer-Encoding: base64', $raw);
    assert_contains(base64_encode("line one\n.line two\n"), $raw);
    assert_contains('Content-Type: application/json; name="data.json"', $raw);
    assert_contains(base64_encode('{"ok":true}'), $raw);

    assert_throws(fn () => (new Mailable())->to('a@example.com')->text('x')->attach('/no/such/file.pdf')->toMessage(), RuntimeException::class, 'Attachment not found');
});

test('SMTP dot-stuffing escapes leading dots and normalises line endings', function () {
    assert_same("a\r\n..\r\n..b\r\nc.d\r\n", SmtpTransport::dotStuff("a\n.\n.b\r\nc.d\n"));
    assert_same("..start", SmtpTransport::dotStuff(".start"));
});

test('SMTP transport reports connection failures instead of hanging', function () {
    $transport = new SmtpTransport('127.0.0.1', 1, 'tls', null, null, 1);
    $message = (new Mailable())->to('a@example.com')->subject('x')->text('x')->toMessage();

    assert_throws(fn () => $transport->send($message), RuntimeException::class, 'could not connect');
    assert_throws(fn () => $transport->send(new MailMessage(from: ['address' => 'a@b.c'])), RuntimeException::class, 'no recipients');
});

test('mailer() builds transports from config and caches them', function () {
    assert_instance_of(ArrayTransport::class, mailer());
    assert_same(mailer(), mailer('array'));
    assert_instance_of(LogTransport::class, mailer('log'));
    assert_instance_of(SendmailTransport::class, mailer('sendmail'));

    config_set('mail.mailers.smtp', ['transport' => 'smtp', 'host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl']);
    assert_instance_of(SmtpTransport::class, mailer('smtp'));

    assert_throws(fn () => mailer('nope'), InvalidArgumentException::class, 'not configured');

    config_set('mail.mailers.weird', ['transport' => 'carrier-pigeon']);
    assert_throws(fn () => mailer('weird'), InvalidArgumentException::class, 'Unsupported');

    $custom = new ArrayTransport();
    mail_use('custom', $custom);
    assert_same($custom, mailer('custom'));
});

test('Mail::send() delivers through the array mailer and fires mail.sent', function () {
    $events = [];
    listen('mail.sent', function (Mailable $mailable, MailMessage $message) use (&$events) {
        $events[] = get_class($mailable) . ':' . $message->subject;
    });

    $message = Mail::to('ann@example.com', 'Ann')->cc('boss@example.com')->send(new MailTestOrderShipped('Ann', 7));

    assert_same('Order #7 shipped', $message->subject);
    assert_same('ann@example.com', $message->to[0]['address']);
    assert_same('boss@example.com', $message->cc[0]['address']);
    assert_count(1, mailer()->messages());
    assert_same($message, mailer()->messages()[0]);
    assert_same(['MailTestOrderShipped:Order #7 shipped'], $events);

    Mail::raw(['x@example.com', 'y@example.com'], 'Plain', 'Just text');
    $raw = mailer()->messages()[1];
    assert_same('Plain', $raw->subject);
    assert_same('Just text', $raw->text);
    assert_count(2, $raw->to);

    forget_listeners('mail.sent');
    assert_throws(fn () => Mail::send((new Mailable())->subject('x')->text('x')), RuntimeException::class, 'no recipients');
});

test('the log mailer writes the rendered message to storage/logs', function () {
    config_set('mail.default', 'log');
    config_set('app.log_level', 'debug');
    $logFile = log_path() . '/app-' . date('Y-m-d') . '.log';
    $before = is_file($logFile) ? filesize($logFile) : 0;

    Mail::raw('log@example.com', 'Logged subject ' . getmypid(), 'Logged body');

    assert_true(is_file($logFile), 'log file exists');
    $tail = (string) file_get_contents($logFile, false, null, $before);
    assert_contains('Mail to log@example.com: Logged subject ' . getmypid(), $tail);
    assert_contains('Logged body', $tail);
    assert_contains('Content-Type: text/plain; charset=UTF-8', $tail);
});

test('Mail::fake() captures mailables without touching the mailer', function () {
    Mail::fake();

    $message = Mail::to('ann@example.com')->send(new MailTestOrderShipped('Ann', 1));
    Mail::to(['bob@example.com'])->send(new MailTestOrderShipped('Bob', 2));
    Mail::queue((new WelcomeMail('Cy'))->to('cy@example.com'));

    assert_true(Mail::isFaked());
    assert_count(0, mailer()->messages(), 'real transport untouched');
    assert_count(3, Mail::sent());
    assert_count(2, Mail::sent(MailTestOrderShipped::class));
    assert_count(3, Mail::sentMessages());
    assert_same('Order #1 shipped', $message->subject);

    Mail::assertSent(MailTestOrderShipped::class);
    Mail::assertSent(MailTestOrderShipped::class, fn (MailTestOrderShipped $m) => $m->order === 2 && $m->hasTo('bob@example.com'));
    Mail::assertSent(WelcomeMail::class, fn ($m) => $m->hasTo('cy@example.com'));
    Mail::assertNotSent(MailTestOrderShipped::class, fn ($m) => $m->order === 99);

    assert_throws(fn () => Mail::assertSent('NoSuchMail'), TestFailure::class, 'not sent');
    assert_throws(fn () => Mail::assertSent(MailTestOrderShipped::class, fn ($m) => false), TestFailure::class, 'filter');
    assert_throws(fn () => Mail::assertNotSent(WelcomeMail::class), TestFailure::class, 'unexpectedly');
    assert_throws(fn () => Mail::assertNothingSent(), TestFailure::class);

    Mail::fake();
    Mail::assertNothingSent();

    Mail::restore();
    assert_false(Mail::isFaked());
    assert_throws(fn () => Mail::assertNothingSent(), TestFailure::class, 'Mail::fake()');
});

test('Mail::queue() sends immediately when the queue module is absent', function () {
    if (events_queue_available()) {
        skip('queue module installed; mail would be dispatched as a job');
    }

    Mail::queue((new WelcomeMail('Dee'))->to('dee@example.com'));

    assert_count(1, mailer()->messages());
    assert_same('dee@example.com', mailer()->messages()[0]->to[0]['address']);
});
