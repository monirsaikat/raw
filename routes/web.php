<?php

// Route definitions: get/post/put/patch/delete/any(path, action, name, middleware).
//
//   get('/posts/{id:\d+}', 'PostController@show', 'posts.show');
//   post('/posts', 'PostController@store', 'posts.store', ['auth', 'throttle:20,1']);
//   group(['prefix' => '/admin', 'middleware' => ['auth', 'can:admin'], 'name' => 'admin.'], function () {
//       get('/', 'AdminController@index', 'dashboard');
//   });
//
// Actions are 'Controller@method' strings so `php console.php route:cache`
// can serialise them; closures work too but cannot be cached. Scaffold a
// whole resource with `php console.php make:crud Post --fields=title:string,body:text`.

get('/', 'HomeController@index', 'home');
