<?php

// Turns a MailMessage into an RFC 5322 / MIME string: multipart/alternative
// for text + HTML, wrapped in multipart/mixed when there are attachments.
// Non-ASCII subjects and display names use RFC 2047 encoded words.

class MimeBuilder
{
    public const EOL = "\r\n";

    public function __construct(private MailMessage $message)
    {
    }

    public function build(): string
    {
        return $this->headers() . self::EOL . self::EOL . $this->body();
    }

    // Header block without the trailing blank line. Bcc is deliberately left
    // out: it is an envelope-only recipient list.
    public function headers(): string
    {
        $m = $this->message;
        $lines = [];

        $lines[] = 'Date: ' . date(DATE_RFC2822);

        if ($m->from !== []) {
            $lines[] = 'From: ' . self::formatAddresses([$m->from]);
        }

        if ($m->to !== []) {
            $lines[] = 'To: ' . self::formatAddresses($m->to);
        }

        if ($m->cc !== []) {
            $lines[] = 'Cc: ' . self::formatAddresses($m->cc);
        }

        if ($m->replyTo !== []) {
            $lines[] = 'Reply-To: ' . self::formatAddresses($m->replyTo);
        }

        $lines[] = 'Subject: ' . self::encodeHeader($m->subject);
        $lines[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . self::domain() . '>';
        $lines[] = 'MIME-Version: 1.0';

        if ($m->priority !== null) {
            $lines[] = 'X-Priority: ' . max(1, min(5, $m->priority));
        }

        $lines[] = 'X-Mailer: ComfreePHP';

        foreach ($m->headers as $name => $value) {
            $lines[] = $name . ': ' . self::sanitize((string) $value);
        }

        $lines[] = $this->contentTypeHeader();

        return implode(self::EOL, $lines);
    }

    // Content-Type header of the top-level part (used by mail()-based sending
    // where headers and body travel separately).
    public function contentTypeHeader(): string
    {
        $m = $this->message;

        if ($m->hasAttachments()) {
            return 'Content-Type: multipart/mixed; boundary="' . $this->boundary('mixed') . '"';
        }

        if ($m->html !== null && $m->text !== null) {
            return 'Content-Type: multipart/alternative; boundary="' . $this->boundary('alt') . '"';
        }

        return $m->html !== null
            ? 'Content-Type: text/html; charset=UTF-8' . self::EOL . 'Content-Transfer-Encoding: quoted-printable'
            : 'Content-Type: text/plain; charset=UTF-8' . self::EOL . 'Content-Transfer-Encoding: quoted-printable';
    }

    public function body(): string
    {
        $m = $this->message;

        if ($m->hasAttachments()) {
            $boundary = $this->boundary('mixed');
            $out = '--' . $boundary . self::EOL . $this->alternativePart() . self::EOL;

            foreach ($m->attachments as $attachment) {
                $out .= '--' . $boundary . self::EOL . $this->attachmentPart($attachment) . self::EOL;
            }

            return $out . '--' . $boundary . '--' . self::EOL;
        }

        if ($m->html !== null && $m->text !== null) {
            return $this->alternativeBody();
        }

        return quoted_printable_encode(self::crlf($m->html ?? $m->text ?? ''));
    }

    // Body-with-headers of the text/HTML part, nested inside multipart/mixed.
    private function alternativePart(): string
    {
        $m = $this->message;

        if ($m->html !== null && $m->text !== null) {
            return 'Content-Type: multipart/alternative; boundary="' . $this->boundary('alt') . '"'
                . self::EOL . self::EOL . $this->alternativeBody();
        }

        return $this->textPart($m->html !== null ? 'text/html' : 'text/plain', $m->html ?? $m->text ?? '');
    }

    private function alternativeBody(): string
    {
        $boundary = $this->boundary('alt');

        return '--' . $boundary . self::EOL
            . $this->textPart('text/plain', (string) $this->message->text) . self::EOL
            . '--' . $boundary . self::EOL
            . $this->textPart('text/html', (string) $this->message->html) . self::EOL
            . '--' . $boundary . '--' . self::EOL;
    }

    private function textPart(string $type, string $content): string
    {
        return 'Content-Type: ' . $type . '; charset=UTF-8' . self::EOL
            . 'Content-Transfer-Encoding: quoted-printable' . self::EOL . self::EOL
            . quoted_printable_encode(self::crlf($content)) . self::EOL;
    }

    private function attachmentPart(array $attachment): string
    {
        $quotedName = '"' . addcslashes(self::encodeHeader((string) $attachment['name']), '"\\') . '"';

        return 'Content-Type: ' . $attachment['mime'] . '; name=' . $quotedName . self::EOL
            . 'Content-Transfer-Encoding: base64' . self::EOL
            . 'Content-Disposition: attachment; filename=' . $quotedName . self::EOL . self::EOL
            . chunk_split(base64_encode((string) $attachment['content']), 76, self::EOL);
    }

    // Deterministic per message so headers() and body() agree.
    private function boundary(string $kind): string
    {
        static $seed = null;

        $seed ??= bin2hex(random_bytes(8));

        return '=_' . $kind . '_' . md5($seed . spl_object_id($this->message) . $kind);
    }

    // "Name <addr>", "addr" — with RFC 2047 encoding of non-ASCII names.
    public static function formatAddresses(array $addresses): string
    {
        return implode(', ', array_map(function (array $a) {
            $name = trim((string) ($a['name'] ?? ''));

            if ($name === '') {
                return $a['address'];
            }

            $name = self::isAscii($name)
                ? '"' . addcslashes($name, '"\\') . '"'
                : self::encodeHeader($name);

            return $name . ' <' . $a['address'] . '>';
        }, $addresses));
    }

    // RFC 2047 "B" encoding for header values with non-ASCII characters.
    public static function encodeHeader(string $value): string
    {
        $value = self::sanitize($value);

        if (self::isAscii($value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    // Bare LF would be encoded as =0A; real CRLF line breaks stay readable.
    public static function crlf(string $value): string
    {
        return (string) preg_replace('/\r?\n/', "\r\n", $value);
    }

    public static function isAscii(string $value): bool
    {
        return preg_match('/[^\x20-\x7E]/', $value) !== 1;
    }

    // Strips CR/LF so user data can never inject extra headers.
    public static function sanitize(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }

    public static function domain(): string
    {
        $host = parse_url((string) config('app.url', ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : (gethostname() ?: 'localhost');
    }
}
