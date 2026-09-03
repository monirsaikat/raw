{extends file='layouts/main.tpl'}

{block name='title'}About — YourApp{/block}

{block name='content'}

<section class="hero pb-4">
    <div class="container">
        <h1>About YourApp</h1>
        <p class="lead mx-auto">
            Placeholder copy describing what your company or product does, who
            it's for, and why it matters. Replace this paragraph with your own story.
        </p>
    </div>
</section>

<section class="container mb-5">
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value">10K+</div>
                <div class="stat-label">Placeholder stat</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value">99.9%</div>
                <div class="stat-label">Placeholder stat</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value">150+</div>
                <div class="stat-label">Placeholder stat</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value">24/7</div>
                <div class="stat-label">Placeholder stat</div>
            </div>
        </div>
    </div>
</section>

<section class="container mb-5">
    <div class="row g-4 align-items-start">
        <div class="col-md-6">
            <h2 class="h4">Our mission</h2>
            <p class="text-muted">
                Placeholder paragraph describing your mission. Explain the problem
                you're solving and why your approach is different, in a sentence or two
                per paragraph so it stays easy to scan.
            </p>
            <p class="text-muted mb-0">
                A second placeholder paragraph goes here, continuing the story or
                adding supporting detail about your team, product, or values.
            </p>
        </div>
        <div class="col-md-6">
            <div class="row g-3">
                <div class="col-12">
                    <div class="feature-card">
                        <div class="icon">V</div>
                        <h3 class="h6">Placeholder value</h3>
                        <p class="text-muted small mb-0">One line describing a value or principle your team follows.</p>
                    </div>
                </div>
                <div class="col-12">
                    <div class="feature-card">
                        <div class="icon">V</div>
                        <h3 class="h6">Another placeholder value</h3>
                        <p class="text-muted small mb-0">One line describing another value or principle.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container mb-5 text-center">
    <h2 class="h4">Want to know more?</h2>
    <p class="text-muted">We'd love to hear from you.</p>
    <a href="{navigate name='contact'}" class="btn btn-brand px-4 py-2">Contact us</a>
</section>

{/block}
