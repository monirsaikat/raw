<?php

// Returning an array (or a Model) from an action sends it as JSON.
// Errors on /api/* are JSON too when the client sends Accept: application/json.
class ApiController
{
    public function ping()
    {
        return [
            'pong' => true,
            'time' => now(),
            'user' => auth_user(),
        ];
    }
}
