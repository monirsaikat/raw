<?php

// Delivers through PHP's mail(), i.e. the sendmail binary or the SMTP
// settings in php.ini. Headers and body are split because mail() adds its
// own To and Subject lines.

class SendmailTransport implements MailTransport
{
    public function send(MailMessage $message): void
    {
        if ($message->to === []) {
            throw new RuntimeException('Sendmail: the message has no To recipients.');
        }

        $builder = new MimeBuilder($message);
        $headers = [];

        foreach (explode(MimeBuilder::EOL, $builder->headers()) as $line) {
            // mail() writes To/Subject itself; folded continuation lines stay attached.
            if (preg_match('/^(To|Subject):/i', $line)) {
                continue;
            }

            $headers[] = $line;
        }

        if ($message->bcc !== []) {
            $headers[] = 'Bcc: ' . MimeBuilder::formatAddresses($message->bcc);
        }

        $sent = @mail(
            MimeBuilder::formatAddresses($message->to),
            MimeBuilder::encodeHeader($message->subject),
            $builder->body(),
            implode(MimeBuilder::EOL, $headers),
            isset($message->from['address']) ? '-f' . escapeshellarg($message->from['address']) : ''
        );

        if (!$sent) {
            throw new RuntimeException('Sendmail: mail() returned false; check the sendmail_path / SMTP settings in php.ini.');
        }
    }
}
