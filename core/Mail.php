<?php

// Mail facade. Mail::to('a@b.c')->send(new WelcomeMail($user)),
// Mail::send($mailable), Mail::raw(...), Mail::queue($mailable). In tests,
// Mail::fake() swaps in an ArrayTransport and records every mailable for
// Mail::assertSent() / assertNothingSent().

class Mail
{
    private static bool $faked = false;
    private static ?ArrayTransport $fakeTransport = null;
    /** @var Mailable[] */
    private static array $sent = [];

    public static function to(string|array $address, ?string $name = null): PendingMail
    {
        return (new PendingMail())->to($address, $name);
    }

    public static function cc(string|array $address, ?string $name = null): PendingMail
    {
        return (new PendingMail())->cc($address, $name);
    }

    public static function bcc(string|array $address, ?string $name = null): PendingMail
    {
        return (new PendingMail())->bcc($address, $name);
    }

    // Sends through the configured mailer (or a named one) and returns the message.
    public static function send(Mailable $mailable, ?string $mailer = null): MailMessage
    {
        $message = $mailable->toMessage();

        if ($message->recipients() === []) {
            throw new RuntimeException(get_class($mailable) . ' has no recipients.');
        }

        if (self::$faked) {
            self::$sent[] = $mailable;
            self::$fakeTransport->send($message);
        } else {
            mailer($mailer)->send($message);
        }

        event('mail.sent', $mailable, $message);

        return $message;
    }

    // Quick plain-text message without a Mailable subclass.
    public static function raw(string|array $to, string $subject, string $text): MailMessage
    {
        return self::send((new Mailable())->to($to)->subject($subject)->text($text));
    }

    // Pushes a SendMailJob when the queue module is installed; sends now otherwise.
    public static function queue(Mailable $mailable, ?string $mailer = null): void
    {
        if (!self::$faked && events_queue_available()) {
            dispatch(new SendMailJob($mailable, $mailer));

            return;
        }

        self::send($mailable, $mailer);
    }

    // --------------------------------------------------------------- fake --

    public static function fake(): void
    {
        self::$faked = true;
        self::$fakeTransport = new ArrayTransport();
        self::$sent = [];
    }

    public static function restore(): void
    {
        self::$faked = false;
        self::$fakeTransport = null;
        self::$sent = [];
    }

    public static function isFaked(): bool
    {
        return self::$faked;
    }

    /** @return Mailable[] mailables sent since fake(), optionally by class */
    public static function sent(?string $mailableClass = null): array
    {
        if ($mailableClass === null) {
            return self::$sent;
        }

        return array_values(array_filter(self::$sent, fn ($m) => $m instanceof $mailableClass));
    }

    /** @return MailMessage[] rendered messages captured by the fake transport */
    public static function sentMessages(): array
    {
        return self::$fakeTransport?->messages() ?? [];
    }

    public static function assertSent(string $mailableClass, ?callable $filter = null): void
    {
        self::guardFaked();

        foreach (self::sent($mailableClass) as $mailable) {
            if ($filter === null || $filter($mailable)) {
                return;
            }
        }

        self::fail($filter === null
            ? "Mailable [$mailableClass] was not sent."
            : "Mailable [$mailableClass] was sent but none matched the filter.");
    }

    public static function assertNotSent(string $mailableClass, ?callable $filter = null): void
    {
        self::guardFaked();

        foreach (self::sent($mailableClass) as $mailable) {
            if ($filter === null || $filter($mailable)) {
                self::fail("Mailable [$mailableClass] was sent unexpectedly.");
            }
        }
    }

    public static function assertNothingSent(): void
    {
        self::guardFaked();

        if (self::$sent !== []) {
            self::fail(count(self::$sent) . ' mailable(s) were sent unexpectedly.');
        }
    }

    private static function guardFaked(): void
    {
        if (!self::$faked) {
            self::fail('Call Mail::fake() before asserting on sent mail.');
        }
    }

    // TestFailure only exists once core/testing.php is loaded.
    private static function fail(string $message): never
    {
        throw class_exists('TestFailure') ? new TestFailure($message) : new RuntimeException($message);
    }
}
