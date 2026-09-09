<?php

// Object form of the auth helpers for one guard: guard('admin')->user().
// Every method delegates to the auth_*() functions with the guard filled in.

class Guard
{
    public function __construct(private string $name)
    {
        auth_guard_config($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function model(): string
    {
        return auth_model($this->name);
    }

    public function user(): ?Model
    {
        return auth_user($this->name);
    }

    public function id(): int|string|null
    {
        return auth_id($this->name);
    }

    public function check(): bool
    {
        return auth_check($this->name);
    }

    public function guest(): bool
    {
        return auth_guest($this->name);
    }

    public function login(Model|int|string $user, bool $remember = false): static
    {
        auth_login($user, $remember, $this->name);

        return $this;
    }

    public function attempt(string $username, string $password, bool $remember = false): bool
    {
        return auth_attempt($username, $password, $remember, $this->name);
    }

    public function logout(): static
    {
        auth_logout($this->name);

        return $this;
    }

    public function intended(?string $default = null): Response
    {
        return auth_intended($default, $this->name);
    }

    // Make this guard the default for the rest of the request.
    public function use(): static
    {
        auth_use_guard($this->name);

        return $this;
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        return auth_guard_config($this->name, $key, $default);
    }
}
