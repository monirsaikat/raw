<?php

// Recipients collected by Mail::to()/cc()/bcc() before send()/queue() puts
// them on the mailable. The mailable's own recipients are kept as well.

class PendingMail
{
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];

    public function to(string|array $address, ?string $name = null): self
    {
        $this->to = array_merge($this->to, Mailable::normalizeAddresses($address, $name));

        return $this;
    }

    public function cc(string|array $address, ?string $name = null): self
    {
        $this->cc = array_merge($this->cc, Mailable::normalizeAddresses($address, $name));

        return $this;
    }

    public function bcc(string|array $address, ?string $name = null): self
    {
        $this->bcc = array_merge($this->bcc, Mailable::normalizeAddresses($address, $name));

        return $this;
    }

    public function send(Mailable $mailable, ?string $mailer = null): MailMessage
    {
        return Mail::send($this->fill($mailable), $mailer);
    }

    public function queue(Mailable $mailable, ?string $mailer = null): void
    {
        Mail::queue($this->fill($mailable), $mailer);
    }

    private function fill(Mailable $mailable): Mailable
    {
        if ($this->to !== []) {
            $mailable->to($this->to);
        }

        if ($this->cc !== []) {
            $mailable->cc($this->cc);
        }

        if ($this->bcc !== []) {
            $mailable->bcc($this->bcc);
        }

        return $mailable;
    }
}
