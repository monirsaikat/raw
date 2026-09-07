<?php

// Value object returned by controllers/middleware: body, status and headers.
// route() sends it; strings and arrays returned from actions are wrapped
// automatically (see send_response()). Kept deliberately small.

class Response implements Stringable
{
    public array $headers = [];

    public function __construct(
        public string $body = '',
        public int $status = 200,
        array $headers = []
    ) {
        $this->withHeaders($headers);
    }

    public function header(string $name, string $value): static
    {
        $this->headers[self::normalizeHeader($name)] = $value;

        return $this;
    }

    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->header($name, (string) $value);
        }

        return $this;
    }

    public function withStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            // An explicit status line, not just http_response_code(): Apache
            // rewrites codes it does not know (419, 422) to 500 otherwise.
            $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
            $reason = function_exists('http_status_text') ? http_status_text($this->status) : '';

            header(trim($protocol . ' ' . $this->status . ' ' . $reason), true, $this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->body;
    }

    public function __toString(): string
    {
        return $this->body;
    }

    private static function normalizeHeader(string $name): string
    {
        return ucwords(strtolower(trim($name)), '-');
    }
}
