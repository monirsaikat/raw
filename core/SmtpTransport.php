<?php

// SMTP client on raw sockets: EHLO, STARTTLS (or implicit TLS on 'ssl'),
// AUTH LOGIN / PLAIN, MAIL FROM / RCPT TO / DATA with dot-stuffing, QUIT.
// One connection per send() keeps it simple and stateless.

class SmtpTransport implements MailTransport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port = 587,
        private ?string $encryption = 'tls',
        private ?string $username = null,
        private ?string $password = null,
        private int $timeout = 30,
        private ?string $localDomain = null
    ) {
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 587),
            ($config['encryption'] ?? null) ?: null,
            ($config['username'] ?? null) ?: null,
            ($config['password'] ?? null) ?: null,
            (int) ($config['timeout'] ?? 30),
            ($config['local_domain'] ?? null) ?: null
        );
    }

    public function send(MailMessage $message): void
    {
        $recipients = $message->recipients();

        if ($recipients === []) {
            throw new RuntimeException('SMTP: the message has no recipients.');
        }

        $from = $message->from['address'] ?? '';

        if ($from === '') {
            throw new RuntimeException('SMTP: the message has no From address.');
        }

        try {
            $this->connect();
            $this->command('MAIL FROM:<' . $from . '>', [250]);

            foreach ($recipients as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            $this->command('DATA', [354]);
            $this->write(self::dotStuff($message->toString()) . "\r\n.");
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            $this->close();
        }
    }

    // Lines starting with "." get an extra dot so they cannot end the DATA
    // section early (RFC 5321 §4.5.2). Also normalises line endings.
    public static function dotStuff(string $data): string
    {
        $data = preg_replace('/\r?\n/', "\r\n", $data);

        return preg_replace('/(^|\r\n)\./', '$1..', $data);
    }

    private function connect(): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $error,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );

        if ($socket === false) {
            throw new RuntimeException("SMTP: could not connect to {$this->host}:{$this->port} ($errno: $error)");
        }

        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeout);

        $this->expect([220]);
        $capabilities = $this->ehlo();

        if ($this->encryption === 'tls') {
            if (!isset($capabilities['STARTTLS'])) {
                throw new RuntimeException('SMTP: the server does not offer STARTTLS.');
            }

            $this->command('STARTTLS', [220]);

            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('SMTP: STARTTLS negotiation failed.');
            }

            // Capabilities may change once the channel is encrypted.
            $capabilities = $this->ehlo();
        }

        if ($this->username !== null) {
            $this->authenticate($capabilities['AUTH'] ?? '');
        }
    }

    // Returns the advertised extensions as ['STARTTLS' => '', 'AUTH' => 'PLAIN LOGIN', ...].
    private function ehlo(): array
    {
        $domain = $this->localDomain ?? MimeBuilder::domain();
        $lines = $this->command('EHLO ' . $domain, [250]);
        $capabilities = [];

        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = array_pad(explode(' ', trim(substr($line, 4)), 2), 2, '');
            $capabilities[strtoupper($name)] = $value;
        }

        return $capabilities;
    }

    private function authenticate(string $methods): void
    {
        $methods = strtoupper($methods);

        if ($methods === '' || str_contains($methods, 'LOGIN')) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode((string) $this->username), [334]);
            $this->command(base64_encode((string) $this->password), [235]);

            return;
        }

        if (str_contains($methods, 'PLAIN')) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235]);

            return;
        }

        throw new RuntimeException("SMTP: no supported AUTH method (server offers: $methods).");
    }

    private function command(string $command, array $expected): array
    {
        $this->write($command);

        return $this->expect($expected, $command);
    }

    private function write(string $data): void
    {
        if (@fwrite($this->socket, $data . "\r\n") === false) {
            throw new RuntimeException('SMTP: connection lost while writing.');
        }
    }

    // Reads a (possibly multi-line) reply and checks its status code.
    private function expect(array $codes, string $command = ''): array
    {
        $lines = [];

        do {
            $line = fgets($this->socket, 4096);

            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);

                throw new RuntimeException('SMTP: ' . (($meta['timed_out'] ?? false) ? 'timed out' : 'connection closed') . ' waiting for a reply' . ($command !== '' ? " to [$command]" : '') . '.');
            }

            $lines[] = rtrim($line, "\r\n");
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($lines[0], 0, 3);

        if (!in_array($code, $codes, true)) {
            // Never echo credentials back in an error message.
            $shown = str_starts_with($command, 'AUTH') || preg_match('/^[A-Za-z0-9+\/=]+$/', $command) ? '[credentials]' : $command;

            throw new RuntimeException('SMTP: unexpected reply ' . implode(' / ', $lines) . ($shown !== '' ? " to [$shown]" : ''));
        }

        return $lines;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
    }
}
