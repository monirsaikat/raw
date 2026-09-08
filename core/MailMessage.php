<?php

// Fully rendered email ready for a transport: addresses are
// ['address' => ..., 'name' => ...] pairs, attachments are
// ['name' => ..., 'content' => raw bytes, 'mime' => ...].

class MailMessage
{
    public function __construct(
        public array $from = [],
        public array $to = [],
        public array $cc = [],
        public array $bcc = [],
        public array $replyTo = [],
        public string $subject = '',
        public ?string $html = null,
        public ?string $text = null,
        public array $attachments = [],
        public array $headers = [],
        public ?int $priority = null
    ) {
    }

    // Every envelope recipient (To, Cc and Bcc) as bare addresses.
    public function recipients(): array
    {
        return array_values(array_unique(array_map(
            fn ($r) => $r['address'],
            array_merge($this->to, $this->cc, $this->bcc)
        )));
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }

    // The full RFC 5322 message (headers + body) built by MimeBuilder.
    public function toString(): string
    {
        return (new MimeBuilder($this))->build();
    }
}
