<?php

// Development default: writes the full rendered message to storage/logs
// through the logger instead of delivering it.

class LogTransport implements MailTransport
{
    public function send(MailMessage $message): void
    {
        // No context array: the logger would append it as JSON a second time.
        log_info(
            'Mail to ' . implode(', ', $message->recipients()) . ': ' . $message->subject
            . "\n" . $message->toString()
        );
    }
}
