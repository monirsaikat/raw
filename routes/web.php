<?php 

get('/', [new HomeController(), 'index'], 'home');
get('/about', [new HomeController(), 'about'], 'about');
get('/user/{id}', [new UserController(), 'show'], 'user');