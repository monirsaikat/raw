<?php


class HomeController
{
    public function index()
    {
        return view('views/home');
    }

    public function about()
    {
        return view('views/about');
    }
}