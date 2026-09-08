<?php

// Sessions stored in the client's cookie: the serialised $_SESSION is
// encrypted with APP_KEY (AES-256-GCM, so it cannot be read or altered) and
// sent back as "<session name>_payload". No server-side storage, which
// suits stateless multi-server setups, at the price of a hard size limit:
// browsers cap a cookie at 4 KB, so only small sessions (auth id, CSRF
// token, a flash message) fit. Larger data is refused with a logged error
// and the session keeps its previous content.

class CookieSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public const MAX_SIZE = 4096;

    public function __construct(private string $cookie, private int $lifetime = 7200)
    {
    }

    public function cookie_name(): string
    {
        return $this->cookie;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $payload = $this->payload();

        // The payload is bound to the session ID it was issued for, so a
        // payload cookie cannot be replayed under another ID.
        if ($payload === null || ($payload['id'] ?? null) !== $id) {
            return '';
        }

        if ((int) ($payload['time'] ?? 0) < time() - $this->lifetime) {
            return '';
        }

        return (string) ($payload['data'] ?? '');
    }

    public function write(string $id, string $data): bool
    {
        $encrypted = encrypt(['id' => $id, 'data' => $data, 'time' => time()]);

        if (strlen($encrypted) > self::MAX_SIZE) {
            log_error('Session not saved: the cookie session driver is limited to ' . self::MAX_SIZE . ' bytes (payload is ' . strlen($encrypted) . ' bytes). Store less in the session or switch SESSION_DRIVER to database.');

            return true;
        }

        $this->send($encrypted, $this->lifetime);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->send('', -3600);

        return true;
    }

    // Nothing accumulates on the server; expiry is checked on read.
    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    public function validateId(string $id): bool
    {
        return ($this->payload()['id'] ?? null) === $id;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    private function payload(): ?array
    {
        $raw = $_COOKIE[$this->cookie] ?? null;

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $payload = decrypt($raw);
        } catch (DecryptException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function send(string $value, int $seconds): void
    {
        if ($value === '') {
            unset($_COOKIE[$this->cookie]);
        } else {
            $_COOKIE[$this->cookie] = $value;
        }

        if (PHP_SAPI === 'cli') {
            return;
        }

        if (headers_sent()) {
            log_error('Session not saved: headers were already sent before the cookie session driver could write.');

            return;
        }

        setcookie($this->cookie, $value, cookie_options() + ['expires' => $seconds > 0 ? 0 : time() + $seconds]);
    }
}
