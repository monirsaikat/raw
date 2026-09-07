{* Fallback error page. Add views/errors/404.tpl etc. to customise one status. *}
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$status} - {$title}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #fdfcfb; color: #1f2328; }
        .box { text-align: center; padding: 2rem; max-width: 32rem; }
        h1 { font-size: 4rem; margin: 0 0 .25rem; letter-spacing: -0.03em; }
        h2 { font-size: 1.25rem; font-weight: 600; margin: 0 0 .75rem; }
        p { color: #6b7280; margin: 0 0 1.5rem; }
        a { color: #ee6c4d; font-weight: 600; text-decoration: none; }
    </style>
</head>

<body>
    <div class="box">
        <h1>{$status}</h1>
        <h2>{$title}</h2>
        <p>{$message}</p>
        <a href="{url}">Go home</a>
    </div>
</body>

</html>
