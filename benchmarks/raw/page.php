<?php

// Plain PHP baseline for the framework comparison: the same page the
// frameworks render (layout, navigation, a list of four items, a form with
// a CSRF token), with a session started like a normal page would.

session_start();

$_SESSION['_token'] ??= bin2hex(random_bytes(32));

$items = [
    ['rank' => 1, 'title' => 'Item One', 'description' => 'Placeholder description for the first item in the list.', 'meta' => '$120'],
    ['rank' => 2, 'title' => 'Item Two', 'description' => 'Placeholder description for the second item in the list.', 'meta' => '$98'],
    ['rank' => 3, 'title' => 'Item Three', 'description' => 'Placeholder description for the third item in the list.', 'meta' => '$76'],
    ['rank' => 4, 'title' => 'Item Four', 'description' => 'Placeholder description for the fourth item in the list.', 'meta' => '$54'],
];

$e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $e($_SESSION['_token']) ?>">
    <title>Raw PHP — Benchmark page</title>
</head>
<body>
    <nav><a href="/">Home</a> <a href="/about">About</a> <a href="/contact">Contact</a></nav>
    <section>
        <h1>Build something great, faster</h1>
        <div class="rank-list">
            <?php foreach ($items as $item): ?>
                <div class="rank-row">
                    <div class="rank-number">#<?= $e($item['rank']) ?></div>
                    <div class="rank-body"><h3><?= $e($item['title']) ?></h3><p><?= $e($item['description']) ?></p></div>
                    <div class="rank-meta"><?= $e($item['meta']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <form method="post" action="/contact">
            <input type="hidden" name="_token" value="<?= $e($_SESSION['_token']) ?>">
            <button type="submit">Send</button>
        </form>
    </section>
    <footer>&copy; <?= date('Y') ?> Raw PHP. All rights reserved.</footer>
</body>
</html>
