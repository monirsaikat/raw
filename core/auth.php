<?php

function auth_id()
{
    return session_get('user_id');
}

function auth_check(): bool
{
    return auth_id() !== null;
}

function auth_user(): ?array
{
    static $user = null;
    static $loaded = false;

    if (!$loaded) {
        $id = auth_id();
        $user = $id ? User::find($id) : null;
        $loaded = true;
    }

    return $user;
}

function auth_login($userId)
{
    session_regenerate();
    session_set('user_id', $userId);
}

function auth_logout()
{
    session_forget('user_id');
    session_regenerate();
}

middleware('auth', function (callable $next) {
    if (!auth_check()) {
        return redirect(navigate(['name' => 'login']));
    }

    return $next();
});

middleware('guest', function (callable $next) {
    if (auth_check()) {
        return redirect(navigate(['name' => 'home']));
    }

    return $next();
});
