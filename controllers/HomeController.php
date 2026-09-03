<?php


class HomeController
{
    public function index()
    {
        return view('views/home', [
            'items' => [
                ['rank' => 1, 'initial' => 'A', 'title' => 'Item One', 'description' => 'Placeholder description for the first item in the list.', 'meta' => '$120'],
                ['rank' => 2, 'initial' => 'B', 'title' => 'Item Two', 'description' => 'Placeholder description for the second item in the list.', 'meta' => '$98'],
                ['rank' => 3, 'initial' => 'C', 'title' => 'Item Three', 'description' => 'Placeholder description for the third item in the list.', 'meta' => '$76'],
                ['rank' => 4, 'initial' => 'D', 'title' => 'Item Four', 'description' => 'Placeholder description for the fourth item in the list.', 'meta' => '$54'],
            ],
        ]);
    }

    public function about()
    {
        return view('views/about');
    }
}