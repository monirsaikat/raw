{extends file='layouts/main.tpl'}

{block name='title'}Register — YourApp{/block}

{block name='content'}

<section class="hero pb-4">
    <div class="container">
        <h1>Create an account</h1>
        <p class="lead mx-auto">Placeholder copy inviting people to sign up.</p>
    </div>
</section>

<section class="container mb-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="contact-card">

                <form method="post" action="{navigate name='register.store'}" novalidate>
                    {csrf_field}

                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control{if isset($errors.name)} is-invalid{/if}"
                            id="name" name="name" value="{$old.name}">
                        {if isset($errors.name)}
                            <div class="invalid-feedback">{$errors.name.0}</div>
                        {/if}
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control{if isset($errors.email)} is-invalid{/if}"
                            id="email" name="email" value="{$old.email}">
                        {if isset($errors.email)}
                            <div class="invalid-feedback">{$errors.email.0}</div>
                        {/if}
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control{if isset($errors.password)} is-invalid{/if}"
                            id="password" name="password">
                        {if isset($errors.password)}
                            <div class="invalid-feedback">{$errors.password.0}</div>
                        {/if}
                    </div>

                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label">Confirm password</label>
                        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation">
                    </div>

                    <button type="submit" class="btn btn-brand px-4 py-2 w-100">Create account</button>
                </form>

                <p class="text-center text-muted small mt-3 mb-0">
                    Already have an account? <a href="{navigate name='login'}">Log in</a>
                </p>

            </div>
        </div>
    </div>
</section>

{/block}
