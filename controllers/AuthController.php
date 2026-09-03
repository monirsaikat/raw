<?php

class AuthController
{
    public function showRegister()
    {
        return view('views/auth/register', [
            'errors' => flash('errors') ?? [],
            'old' => array_merge(['name' => '', 'email' => ''], flash('old') ?? []),
        ]);
    }

    public function register()
    {
        $data = input();

        $errors = validate($data, [
            'name' => 'required|max:100',
            'email' => 'required|email|max:150',
            'password' => 'required|min:8|confirmed',
        ]);

        if (empty($errors['email']) && User::findByEmail($data['email'] ?? '')) {
            $errors['email'][] = 'An account with this email already exists.';
        }

        if ($errors) {
            flash('errors', $errors);
            flash('old', ['name' => $data['name'] ?? '', 'email' => $data['email'] ?? '']);

            return redirect(navigate(['name' => 'register']));
        }

        $id = User::create($data['name'], $data['email'], $data['password']);

        auth_login($id);

        return redirect(navigate(['name' => 'account']));
    }

    public function showLogin()
    {
        return view('views/auth/login', [
            'errors' => flash('errors') ?? [],
            'old' => array_merge(['email' => ''], flash('old') ?? []),
        ]);
    }

    public function login()
    {
        $data = input();

        $email = $data['email'] ?? null;
        $user = $email !== null ? User::findByEmail($email) : null;

        if (!$user || !password_verify($data['password'] ?? '', $user['password'])) {
            flash('errors', ['email' => ['Those credentials do not match our records.']]);
            flash('old', ['email' => $data['email'] ?? '']);

            return redirect(navigate(['name' => 'login']));
        }

        auth_login($user['id']);

        return redirect(navigate(['name' => 'account']));
    }

    public function logout()
    {
        auth_logout();

        return redirect(navigate(['name' => 'home']));
    }

    public function account()
    {
        return view('views/account', [
            'user' => auth_user(),
        ]);
    }
}
