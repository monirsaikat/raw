{extends file='layouts/main.tpl'}

{block name='title'}Contact — YourApp{/block}

{block name='content'}

<section class="hero pb-4">
    <div class="container">
        <h1>Get in touch</h1>
        <p class="lead mx-auto">
            Placeholder copy inviting people to reach out. Fill in the form below
            and we'll get back to you.
        </p>
    </div>
</section>

<section class="container mb-5">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="contact-card">

                {if $success}
                    <div class="alert alert-success" role="alert">{$success}</div>
                {/if}

                <form method="post" action="{navigate name='contact.store'}" novalidate>
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
                        <label for="message" class="form-label">Message</label>
                        <textarea class="form-control{if isset($errors.message)} is-invalid{/if}"
                            id="message" name="message" rows="5">{$old.message}</textarea>
                        {if isset($errors.message)}
                            <div class="invalid-feedback">{$errors.message.0}</div>
                        {/if}
                    </div>

                    <button type="submit" class="btn btn-brand px-4 py-2">Send message</button>
                </form>

            </div>
        </div>
    </div>
</section>

{/block}
