<?php

// Thrown by authorize() / Gate::authorize(); rendered as a 403 response.

class AuthorizationException extends HttpException
{
    public function __construct(string $message = 'This action is unauthorized.')
    {
        parent::__construct(403, $message);
    }
}
