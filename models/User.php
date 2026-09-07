<?php

class User extends Model
{
    protected static string $table = 'users';
    protected static array $fillable = ['name', 'email', 'password'];
    protected static array $hidden = ['password', 'remember_token'];

    public static function findByEmail(string $email): ?static
    {
        return static::where('email', $email)->first();
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, (string) $this->password);
    }
}
