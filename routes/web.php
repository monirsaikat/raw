<?php 

get('/', 'HomeController@index', 'home');
get('/about', 'HomeController@about', 'about');
get('/user/{id}', 'UserController@show', 'user');

get('/contact', 'ContactController@index', 'contact');
post('/contact', 'ContactController@store', 'contact.store');

get('/register', 'AuthController@showRegister', 'register', ['guest']);
post('/register', 'AuthController@register', 'register.store', ['guest']);
get('/login', 'AuthController@showLogin', 'login', ['guest']);
post('/login', 'AuthController@login', 'login.store', ['guest']);
post('/logout', 'AuthController@logout', 'logout', ['auth']);
get('/account', 'AuthController@account', 'account', ['auth']);