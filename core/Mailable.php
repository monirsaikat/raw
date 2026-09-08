<?php

// Base class for app emails (mail/*.php, scaffold with make:mail). Configure
// the message in build() with the fluent setters, then Mail::send($mailable).
// Templates are ordinary Smarty views: view('mail/welcome', [...]) renders
// views/mail/welcome.tpl; text() adds a plain-text alternative.

class Mailable
{
    protected array $to = [];
    protected array $cc = [];
    protected array $bcc = [];
    protected array $replyTo = [];
    protected ?array $from = null;
    protected string $subject = '';
    protected ?string $view = null;
    protected array $viewData = [];
    protected ?string $html = null;
    protected ?string $text = null;
    protected array $attachments = [];
    protected array $headers = [];
    protected ?int $priority = null;
    private bool $built = false;

    // Override in subclasses: $this->subject('Welcome')->view('mail/welcome', [...]).
    public function build(): void
    {
    }

    // ------------------------------------------------------------ setters --

    public function to(string|array $address, ?string $name = null): static
    {
        $this->to = array_merge($this->to, self::normalizeAddresses($address, $name));

        return $this;
    }

    public function cc(string|array $address, ?string $name = null): static
    {
        $this->cc = array_merge($this->cc, self::normalizeAddresses($address, $name));

        return $this;
    }

    public function bcc(string|array $address, ?string $name = null): static
    {
        $this->bcc = array_merge($this->bcc, self::normalizeAddresses($address, $name));

        return $this;
    }

    public function replyTo(string|array $address, ?string $name = null): static
    {
        $this->replyTo = array_merge($this->replyTo, self::normalizeAddresses($address, $name));

        return $this;
    }

    public function from(string $address, ?string $name = null): static
    {
        $this->from = ['address' => $address, 'name' => $name ?? ''];

        return $this;
    }

    public function subject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    // Smarty template rendered as the HTML body.
    public function view(string $template, array $data = []): static
    {
        $this->view = $template;
        $this->viewData = array_merge($this->viewData, $data);

        return $this;
    }

    // Extra data for the template without changing it.
    public function with(string|array $key, mixed $value = null): static
    {
        $this->viewData = array_merge($this->viewData, is_array($key) ? $key : [$key => $value]);

        return $this;
    }

    // Plain-text body (or alternative when a view/html is also set).
    public function text(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    // Raw HTML body, bypassing templates.
    public function html(string $html): static
    {
        $this->html = $html;

        return $this;
    }

    public function attach(string $path, ?string $name = null, ?string $mime = null): static
    {
        $this->attachments[] = ['path' => $path, 'name' => $name ?? basename($path), 'mime' => $mime];

        return $this;
    }

    public function attachData(string $data, string $name, string $mime = 'application/octet-stream'): static
    {
        $this->attachments[] = ['content' => $data, 'name' => $name, 'mime' => $mime];

        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    // 1 = highest, 3 = normal, 5 = lowest (X-Priority).
    public function priority(int $level = 3): static
    {
        $this->priority = $level;

        return $this;
    }

    // ------------------------------------------------------------ reading --

    public function hasTo(string $address): bool
    {
        return in_array($address, array_column($this->to, 'address'), true);
    }

    public function hasCc(string $address): bool
    {
        return in_array($address, array_column($this->cc, 'address'), true);
    }

    public function hasBcc(string $address): bool
    {
        return in_array($address, array_column($this->bcc, 'address'), true);
    }

    public function getTo(): array
    {
        return $this->to;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getView(): ?string
    {
        return $this->view;
    }

    public function getViewData(): array
    {
        return $this->viewData;
    }

    // Runs build() once so the same mailable can be inspected and sent.
    public function prepare(): static
    {
        if (!$this->built) {
            $this->built = true;
            $this->build();
        }

        return $this;
    }

    // The HTML body (rendered template, raw html or the text as fallback).
    public function render(): string
    {
        $this->prepare();

        if ($this->view !== null) {
            return view($this->view, $this->viewData + ['subject' => $this->subject]);
        }

        return $this->html ?? $this->text ?? '';
    }

    public function toMessage(): MailMessage
    {
        $this->prepare();

        $html = $this->view !== null ? $this->render() : $this->html;

        // A text-only mailable stays text/plain; nothing else gets an empty body.
        if ($html === null && $this->text === null) {
            throw new RuntimeException(static::class . ' has no body: call view(), html() or text() in build().');
        }

        return new MailMessage(
            from: $this->from ?? [
                'address' => (string) config('mail.from.address', 'hello@example.com'),
                'name' => (string) config('mail.from.name', ''),
            ],
            to: $this->to,
            cc: $this->cc,
            bcc: $this->bcc,
            replyTo: $this->replyTo,
            subject: $this->subject,
            html: $html,
            text: $this->text,
            attachments: array_map([$this, 'loadAttachment'], $this->attachments),
            headers: $this->headers,
            priority: $this->priority
        );
    }

    private function loadAttachment(array $attachment): array
    {
        if (isset($attachment['path'])) {
            if (!is_file($attachment['path'])) {
                throw new RuntimeException('Attachment not found: ' . $attachment['path']);
            }

            $attachment['content'] = (string) file_get_contents($attachment['path']);
            $attachment['mime'] ??= (function_exists('mime_content_type') ? @mime_content_type($attachment['path']) : null) ?: 'application/octet-stream';
        }

        return [
            'name' => $attachment['name'],
            'content' => $attachment['content'],
            'mime' => $attachment['mime'] ?? 'application/octet-stream',
        ];
    }

    // 'a@b.c' | ['a@b.c', 'd@e.f'] | ['a@b.c' => 'Ann'] | ['address' => .., 'name' => ..] | a list of those
    public static function normalizeAddresses(string|array $address, ?string $name = null): array
    {
        if (is_string($address)) {
            return [self::address($address, $name)];
        }

        if (isset($address['address'])) {
            return [self::address((string) $address['address'], $address['name'] ?? null)];
        }

        $result = [];

        foreach ($address as $key => $value) {
            if (is_array($value)) {
                $result = array_merge($result, self::normalizeAddresses($value));
            } elseif (is_string($key) && str_contains($key, '@')) {
                $result[] = self::address($key, (string) $value);
            } else {
                $result[] = self::address((string) $value);
            }
        }

        return $result;
    }

    private static function address(string $address, ?string $name = null): array
    {
        $address = trim($address);

        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address [$address].");
        }

        return ['address' => $address, 'name' => trim((string) $name)];
    }
}
