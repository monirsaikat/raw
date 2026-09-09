<!doctype html>
<html lang="{$app_locale|default:'en'}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {csrf_meta}

    <link href="{asset path='assets/vendor/bootstrap/css/bootstrap.min.css'}" rel="stylesheet">
    <link href="{asset path='assets/css/app.css'}" rel="stylesheet">

    <title>{block name='title'}{$app_name}{/block}</title>

    {block name='styles'}{/block}
</head>

<body>

    <nav class="navbar navbar-expand-md site-navbar">
        <div class="container">
            <a class="navbar-brand" href="{navigate name='home'}">
                <span class="brand-mark">{$app_name|substr:0:1|upper}</span>
                {$app_name}
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav"
                aria-controls="siteNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="siteNav">
                <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
                    {* Add your links here, e.g.
                       <li class="nav-item"><a class="nav-link{if 'posts.*'|route_is} active{/if}" href="{navigate name='posts.index'}">Posts</a></li>
                       {if $auth_user} ... {$auth_user.name} ... {else} ... {/if} *}
                    <li class="nav-item">
                        <a class="nav-link{if 'home'|route_is} active{/if}" href="{navigate name='home'}">{t key='messages.nav_home'}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{url path='docs/index.html'}">{t key='messages.nav_docs'}</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    {include file='includes/alerts.tpl'}

    {block name='content'}{/block}

    <footer class="site-footer">
        <div class="container d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
            <span>&copy; {current_year} {$app_name}</span>
            <span>{t key='messages.footer_built_with'}</span>
        </div>
        {if $app_debug}
            <div class="container mt-2 small text-muted">{perf_stats}</div>
        {/if}
    </footer>

    <script src="{asset path='assets/vendor/bootstrap/js/bootstrap.bundle.min.js'}"></script>
    {block name='scripts'}{/block}
</body>

</html>
