<?php

// Thrown by abort(); the exception handler turns it into a response with the
// given status, optional message and extra headers (e.g. Allow, Retry-After).

class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }
}
