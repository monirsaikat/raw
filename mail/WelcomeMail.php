<?php

// Example mailable. Send it with:
//   Mail::to($user->email)->send(new WelcomeMail($user->name));
// or queue it (needs the queue module):
//   Mail::queue((new WelcomeMail($user->name))->to($user->email));

class WelcomeMail extends Mailable
{
    public function __construct(private string $name)
    {
    }

    public function build(): void
    {
        $this->subject('Welcome to ' . app_name())
            ->view('mail/welcome', [
                'name' => $this->name,
                'url' => app_url() . '/account',
            ])
            ->text('Welcome, ' . $this->name . "!\n\nThanks for joining " . app_name() . '. Your account is ready: ' . app_url() . '/account');
    }
}
