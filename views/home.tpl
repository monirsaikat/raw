{extends file='layouts/main.tpl'}

{block name='title'}YourApp — Placeholder Homepage{/block}

{block name='content'}

<section class="hero">
    <div class="container">
        <h1>Build something great, faster</h1>
        <p class="lead mx-auto">
            This is placeholder copy for your homepage hero. Swap it out with your
            own headline and description once the design is ready.
        </p>
        <div class="d-flex justify-content-center gap-2 mt-4">
            <a href="{navigate name='contact'}" class="btn btn-brand px-4 py-2">Get Started</a>
            <a href="{navigate name='about'}" class="btn btn-brand-outline px-4 py-2">Learn More</a>
        </div>
    </div>
</section>

<section class="container mb-5">
    <div class="d-flex justify-content-between align-items-end mb-3">
        <h2 class="h4 mb-0">Latest items</h2>
        <span class="text-muted small">placeholder list</span>
    </div>

    <div class="rank-list">
        {foreach $items as $item}
            <div class="rank-row">
                <div class="rank-number">#{$item.rank}</div>
                <div class="rank-icon">{$item.initial}</div>
                <div class="rank-body">
                    <h3>{$item.title}</h3>
                    <p>{$item.description}</p>
                </div>
                <div class="rank-meta">{$item.meta}</div>
            </div>
        {/foreach}
    </div>
</section>

<section class="container mb-5">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="feature-card">
                <div class="icon">1</div>
                <h3 class="h6">Feature one</h3>
                <p class="text-muted small mb-0">Short placeholder description of the first feature goes here.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature-card">
                <div class="icon">2</div>
                <h3 class="h6">Feature two</h3>
                <p class="text-muted small mb-0">Short placeholder description of the second feature goes here.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="feature-card">
                <div class="icon">3</div>
                <h3 class="h6">Feature three</h3>
                <p class="text-muted small mb-0">Short placeholder description of the third feature goes here.</p>
            </div>
        </div>
    </div>
</section>

{/block}
