<?php 

get('/', 'HomeController@index', 'home');
get('/about', 'HomeController@about', 'about');
get('/user/{id}', 'UserController@show', 'user');

get('/contact', 'ContactController@index', 'contact');
post('/contact', 'ContactController@store', 'contact.store');