<?php

// Delivery backend contract. Implementations: SmtpTransport, SendmailTransport,
// LogTransport, ArrayTransport. Throw a RuntimeException on failure.

interface MailTransport
{
    public function send(MailMessage $message): void;
}
