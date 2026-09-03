<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="{asset path='assets/vendor/bootstrap/css/bootstrap.min.css'}" rel="stylesheet">
    <link href="{asset path='assets/css/app.css'}" rel="stylesheet">

    <title>{block name='title'}YourApp{/block}</title>

    {block name='styles'}{/block}
</head>

<body>

    <nav class="navbar navbar-expand-md site-navbar">
        <div class="container">
            <a class="navbar-brand" href="{navigate name='home'}">
                <span class="brand-mark">Y</span>
                YourApp
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav"
                aria-controls="siteNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="siteNav">
                <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
                    <li class="nav-item">
                        <a class="nav-link" href="{navigate name='home'}">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{navigate name='about'}">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{navigate name='contact'}">Contact</a>
                    </li>
                    <li class="nav-item ms-md-2">
                        <a class="btn btn-brand btn-sm px-3" href="{navigate name='contact'}">Get in touch</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    {block name='content'}{/block}

    <footer class="site-footer">
        <div class="container d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
            <span>&copy; {current_year} YourApp. All rights reserved.</span>
            <div class="d-flex gap-3">
                <a href="{navigate name='home'}">Home</a>
                <a href="{navigate name='about'}">About</a>
                <a href="{navigate name='contact'}">Contact</a>
            </div>
        </div>
    </footer>

    <script src="{asset path='assets/vendor/bootstrap/js/bootstrap.bundle.min.js'}"></script>
</body>

</html>
