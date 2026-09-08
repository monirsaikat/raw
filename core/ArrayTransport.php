<?php

// Keeps sent messages in memory. Used by Mail::fake() and the 'array' mailer.

class ArrayTransport implements MailTransport
{
    private array $messages = [];

    public function send(MailMessage $message): void
    {
        $this->messages[] = $message;
    }

    /** @return MailMessage[] */
    public function messages(): array
    {
        return $this->messages;
    }

    public function flush(): void
    {
        $this->messages = [];
    }
}
