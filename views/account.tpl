{extends file='layouts/main.tpl'}

{block name='title'}My Account — {$app_name}{/block}

{block name='content'}

<section class="hero pb-4">
    <div class="container">
        <h1>Welcome, {$user.name}</h1>
        <p class="lead mx-auto">This page is only reachable when logged in — protected by the 'auth' middleware.</p>
    </div>
</section>

<section class="container mb-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="contact-card">
                <p class="mb-1"><strong>Name:</strong> {$user.name}</p>
                <p class="mb-3"><strong>Email:</strong> {$user.email}</p>

                <form method="post" action="{navigate name='logout'}">
                    {csrf_field}
                    <button type="submit" class="btn btn-brand-outline px-4 py-2">Log out</button>
                </form>
            </div>
        </div>
    </div>
</section>

{/block}
