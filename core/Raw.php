<?php

// A SQL fragment the query builder must not quote or bind. Only ever build
// one from trusted, developer-written strings — never from request data.

final class Raw implements Stringable
{
    public function __construct(public readonly string $sql)
    {
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
