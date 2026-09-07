<?php

// Thrown by validated(). The exception handler flashes $errors and $input
// and redirects back, or returns a 422 JSON payload for API clients.

class ValidationException extends HttpException
{
    public function __construct(
        public readonly array $errors,
        public readonly array $input = [],
        string $message = 'The given data was invalid.'
    ) {
        parent::__construct(422, $message);
    }
}
