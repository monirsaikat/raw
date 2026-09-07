<?php

class UserController
{
    public function show($id)
    {
        return view('user', [
            'user' => User::findOrFail($id),
        ]);
    }
}
