<?php

class AuthController
{
    public function showRegister()
    {
        return view('auth/register');
    }

    public function register()
    {
        $data = validated(input(), [
            'name' => 'required|max:100',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|min:8|confirmed',
        ], [
            'email.unique' => 'An account with this email already exists.',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => User::hashPassword($data['password']),
        ]);

        auth_login($user);

        return redirect_route('account');
    }

    public function showLogin()
    {
        return view('auth/login');
    }

    public function login()
    {
        $data = validated(input(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!auth_attempt($data['email'], $data['password'], has_input('remember'))) {
            return back_with_errors(['email' => ['Those credentials do not match our records.']]);
        }

        return auth_intended(route_url('account'));
    }

    public function logout()
    {
        auth_logout();

        return redirect_route('home');
    }

    public function account()
    {
        return view('account', ['user' => auth_user()]);
    }
}
