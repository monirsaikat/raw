{extends file='layouts/main.tpl'}

{block name='title'}{$user.name} — {$app_name}{/block}

{block name='content'}

<section class="hero pb-4">
    <div class="container">
        <h1>{$user.name}</h1>
        <p class="lead mx-auto">Member #{$user.id} · joined {$user.created_at}</p>
    </div>
</section>

{/block}
