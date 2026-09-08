<?php

// Queue job created by Mail::queue(). Only referenced when the queue module
// provides class Job (see events_queue_available()), so the autoloader never
// pulls this file in without it. The mailable is serialized with the job.

class SendMailJob extends Job
{
    public function __construct(public Mailable $mailable, public ?string $mailer = null)
    {
    }

    public function handle(): void
    {
        Mail::send($this->mailable, $this->mailer);
    }
}
