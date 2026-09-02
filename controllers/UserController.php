<?php

class UserController
{
    public function show($id)
    {
        return view('views/user', [
            'id' => $id
        ]);
    }
}