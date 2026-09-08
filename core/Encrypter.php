<?php

// AES-256-GCM encryption keyed from APP_KEY. GCM authenticates the
// ciphertext, so a tampered payload fails to decrypt instead of decrypting
// to garbage; decrypt() throws DecryptException in that case. The payload
// is base64(json{iv, value, tag}), all three fields base64 themselves, so
// it is safe in cookies, URLs and text columns.

class Encrypter
{
    public const CIPHER = 'aes-256-gcm';

    private string $key;

    public function __construct(string $key)
    {
        $this->key = self::derive_key($key);
    }

    // "base64:..." keys (from key:generate) decode to raw bytes; a 32-byte
    // raw key is used as is; anything else is hashed to 32 bytes.
    public static function derive_key(string $key): string
    {
        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set. Run: php console.php key:generate');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('APP_KEY is not valid base64.');
            }

            $key = $decoded;
        }

        return strlen($key) === 32 ? $key : hash('sha256', $key, true);
    }

    // Any JSON-serialisable value. Objects come back as arrays.
    public function encrypt($value): string
    {
        return $this->encrypt_string(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $payload)
    {
        $json = $this->decrypt_string($payload);

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DecryptException('The payload does not contain valid JSON.');
        }
    }

    public function encrypt_string(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($cipher === false) {
            throw new RuntimeException('Could not encrypt the data.');
        }

        $json = json_encode([
            'iv' => base64_encode($iv),
            'value' => base64_encode($cipher),
            'tag' => base64_encode($tag),
        ], JSON_THROW_ON_ERROR);

        return base64_encode($json);
    }

    public function decrypt_string(string $payload): string
    {
        $parts = self::parse_payload($payload);

        $plain = openssl_decrypt(
            $parts['value'],
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $parts['iv'],
            $parts['tag']
        );

        // A wrong key, a flipped bit or a forged tag all land here.
        if ($plain === false) {
            throw new DecryptException('The payload could not be decrypted.');
        }

        return $plain;
    }

    // Structural check only; authenticity is verified by the GCM tag.
    private static function parse_payload(string $payload): array
    {
        $json = base64_decode($payload, true);
        $parts = $json === false ? null : json_decode($json, true);

        if (!is_array($parts) || !isset($parts['iv'], $parts['value'], $parts['tag'])
            || !is_string($parts['iv']) || !is_string($parts['value']) || !is_string($parts['tag'])) {
            throw new DecryptException('The payload is invalid.');
        }

        $iv = base64_decode($parts['iv'], true);
        $value = base64_decode($parts['value'], true);
        $tag = base64_decode($parts['tag'], true);

        if ($iv === false || strlen($iv) !== 12 || $value === false || $tag === false || strlen($tag) !== 16) {
            throw new DecryptException('The payload is invalid.');
        }

        return ['iv' => $iv, 'value' => $value, 'tag' => $tag];
    }
}
