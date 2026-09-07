<?php

class ContactController
{
    public function index()
    {
        // $errors, $old and $flash are available in every template automatically.
        return view('contact');
    }

    public function store()
    {
        // On failure validated() redirects back with the errors and old input.
        $data = validated(input(), [
            'name' => 'required|max:100',
            'email' => 'required|email|max:150',
            'message' => 'required|max:2000',
        ]);

        Message::create($data);

        flash('success', "Thanks, {$data['name']}! Your message has been received.");

        return redirect_route('contact');
    }
}
