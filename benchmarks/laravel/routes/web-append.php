
// --- ComfreePHP comparison benchmark (web middleware: session, cookies, CSRF) ---
Route::get('/bench/page', function () {
    return view('bench.page', [
        'items' => [
            ['rank' => 1, 'title' => 'Item One', 'description' => 'Placeholder description for the first item in the list.', 'meta' => '$120'],
            ['rank' => 2, 'title' => 'Item Two', 'description' => 'Placeholder description for the second item in the list.', 'meta' => '$98'],
            ['rank' => 3, 'title' => 'Item Three', 'description' => 'Placeholder description for the third item in the list.', 'meta' => '$76'],
            ['rank' => 4, 'title' => 'Item Four', 'description' => 'Placeholder description for the fourth item in the list.', 'meta' => '$54'],
        ],
    ]);
});
