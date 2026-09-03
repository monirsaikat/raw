<?php

class ContactController
{
    public function index()
    {
        return view('views/contact', [
            'errors' => flash('errors') ?? [],
            'old' => array_merge(
                ['name' => '', 'email' => '', 'message' => ''],
                flash('old') ?? []
            ),
            'success' => flash('success'),
        ]);
    }

    public function store()
    {
        $data = input();

        $errors = validate($data, [
            'name' => 'required|max:100',
            'email' => 'required|email|max:150',
            'message' => 'required|max:2000',
        ]);

        if ($errors) {
            flash('errors', $errors);
            flash('old', $data);

            return redirect(navigate(['name' => 'contact']));
        }

        flash('success', "Thanks, {$data['name']}! Your message has been received.");

        return redirect(navigate(['name' => 'contact']));
    }
}
