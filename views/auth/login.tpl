{extends file='layouts/main.tpl'}

{block name='title'}Login — {$app_name}{/block}

{block name='content'}

<section class="hero pb-4">
    <div class="container">
        <h1>Welcome back</h1>
        <p class="lead mx-auto">Placeholder copy inviting people to log in.</p>
    </div>
</section>

<section class="container mb-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="contact-card">

                <form method="post" action="{navigate name='login.store'}" novalidate>
                    {csrf_field}

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control{if isset($errors.email)} is-invalid{/if}"
                            id="email" name="email" value="{$old.email|default:''}" autocomplete="email">
                        {if isset($errors.email)}
                            <div class="invalid-feedback">{$errors.email.0}</div>
                        {/if}
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control{if isset($errors.password)} is-invalid{/if}"
                            id="password" name="password" autocomplete="current-password">
                        {if isset($errors.password)}
                            <div class="invalid-feedback">{$errors.password.0}</div>
                        {/if}
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1"
                            {if isset($old.remember)}checked{/if}>
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>

                    <button type="submit" class="btn btn-brand px-4 py-2 w-100">Log in</button>
                </form>

                <p class="text-center text-muted small mt-3 mb-0">
                    Don't have an account? <a href="{navigate name='register'}">Register</a>
                </p>

            </div>
        </div>
    </div>
</section>

{/block}
