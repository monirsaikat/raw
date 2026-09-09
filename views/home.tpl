{extends file='layouts/main.tpl'}

{block name='title'}{t key='messages.welcome' app=$app_name}{/block}

{block name='content'}

<section class="hero">
    <div class="container">
        <span class="hero-eyebrow">{t key='messages.eyebrow'}</span>
        <h1>{t key='messages.welcome' app=$app_name}</h1>
        <p class="lead mx-auto">{t key='messages.tagline'}</p>

        <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
            <a href="{url path='docs/index.html'}" class="btn btn-brand px-4 py-2">{t key='messages.read_docs'}</a>
            <a href="#next-steps" class="btn btn-brand-outline px-4 py-2">{t key='messages.next_steps'}</a>
        </div>

        <div class="hero-badges">
            <span class="badge-pill">PHP {$php_version}</span>
            <span class="badge-pill">{$app_name} {$framework_version}</span>
            <span class="badge-pill">{$app_env|default:'production'}</span>
            <span class="badge-pill">{t key='messages.locale'}: {$app_locale}</span>
        </div>
    </div>
</section>

<section class="container mb-5" id="next-steps">
    <div class="d-flex justify-content-between align-items-end mb-3">
        <h2 class="h4 mb-0">{t key='messages.next_steps'}</h2>
        <span class="text-muted small">routes/web.php · controllers/HomeController.php · views/home.tpl</span>
    </div>

    <div class="rank-list">
        <div class="rank-row">
            <div class="rank-number">1</div>
            <div class="rank-icon">.env</div>
            <div class="rank-body">
                <h3>{t key='messages.step_env_title'}</h3>
                <p>{t key='messages.step_env_body'}</p>
            </div>
            <div class="rank-meta"><code>php console.php key:generate</code></div>
        </div>
        <div class="rank-row">
            <div class="rank-number">2</div>
            <div class="rank-icon">DB</div>
            <div class="rank-body">
                <h3>{t key='messages.step_db_title'}</h3>
                <p>{t key='messages.step_db_body'}</p>
            </div>
            <div class="rank-meta"><code>php console.php migrate</code></div>
        </div>
        <div class="rank-row">
            <div class="rank-number">3</div>
            <div class="rank-icon">{ }</div>
            <div class="rank-body">
                <h3>{t key='messages.step_crud_title'}</h3>
                <p>{t key='messages.step_crud_body'}</p>
            </div>
            <div class="rank-meta"><code>php console.php make:crud Post --fields=title:string,body:text --routes</code></div>
        </div>
        <div class="rank-row">
            <div class="rank-number">4</div>
            <div class="rank-icon">✓</div>
            <div class="rank-body">
                <h3>{t key='messages.step_test_title'}</h3>
                <p>{t key='messages.step_test_body'}</p>
            </div>
            <div class="rank-meta"><code>php console.php test</code></div>
        </div>
    </div>
</section>

<section class="container mb-5">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="feature-card">
                <div class="icon">⚡</div>
                <h3 class="h6">{t key='messages.feature_fast_title'}</h3>
                <p class="text-muted small mb-0">{t key='messages.feature_fast_body'}</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature-card">
                <div class="icon">◈</div>
                <h3 class="h6">{t key='messages.feature_batteries_title'}</h3>
                <p class="text-muted small mb-0">{t key='messages.feature_batteries_body'}</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature-card">
                <div class="icon">Aあ</div>
                <h3 class="h6">{t key='messages.feature_lang_title'}</h3>
                <p class="text-muted small mb-2">{t key='messages.feature_lang_body'}</p>
                <div class="d-flex flex-wrap gap-1">
                    {foreach $locales as $locale}
                        <a class="badge-pill{if $locale == $app_locale} active{/if}" href="?lang={$locale}">{$locale}</a>
                    {/foreach}
                </div>
            </div>
        </div>
    </div>
</section>

{/block}
