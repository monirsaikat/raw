<?php

class User
{
    public static function find($id): ?array
    {
        return Database::selectOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::selectOne('SELECT * FROM users WHERE email = ?', [$email]);
    }

    public static function create(string $name, string $email, string $password): string
    {
        return Database::insert(
            'INSERT INTO users (name, email, password) VALUES (?, ?, ?)',
            [$name, $email, password_hash($password, PASSWORD_DEFAULT)]
        );
    }
}
