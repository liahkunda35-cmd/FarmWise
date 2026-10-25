<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= brand_head() ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/fonts/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=p7">
</head>
<body class="public-body">
<header class="pub-nav">
    <a class="brand" href="<?= e(url()) ?>">
        <?= brand_logo() ?>
    </a>
    <button type="button" class="pub-nav-toggle" data-pub-nav-toggle aria-expanded="false" aria-controls="pub-nav-links" aria-label="Open menu">☰</button>
    <nav id="pub-nav-links" data-pub-nav>
        <a href="<?= e(url()) ?>">Home</a>
        <a href="#about">About</a>
        <a href="#benefits">Benefits</a>
        <a href="#how-it-works">How it works</a>
        <a href="<?= e(url('login')) ?>">Sign in</a>
        <a class="btn btn-gold btn-sm" href="<?= e(url('register')) ?>">Get started</a>
    </nav>
</header>

<section class="hero">
    <?php
    $heroSlides = config('hero_images');
    if (!is_array($heroSlides) || !$heroSlides) {
        $heroSlides = [config('hero_image')];
    }
    $heroCount = count($heroSlides);
    ?>
    <div class="hero-media hero-slideshow" data-hero-slideshow data-interval="5000">
        <div class="hero-slides" aria-hidden="true">
            <?php foreach ($heroSlides as $i => $slide): ?>
                <?php
                $slideUrl = is_string($slide) && (str_starts_with($slide, 'http://') || str_starts_with($slide, 'https://'))
                    ? $slide
                    : asset((string) $slide);
                ?>
                <div class="hero-slide<?= $i === 0 ? ' is-active' : '' ?>" style="background-image:url('<?= e($slideUrl) ?>')"></div>
            <?php endforeach; ?>
        </div>
        <?php if ($heroCount > 1): ?>
        <div class="hero-slideshow-ui">
            <button type="button" class="hero-nav-btn" data-hero-prev aria-label="Previous image">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <div class="hero-dots" role="tablist" aria-label="Hero images">
                <?php for ($d = 0; $d < $heroCount; $d++): ?>
                    <button type="button" class="hero-dot<?= $d === 0 ? ' is-active' : '' ?>" data-hero-dot="<?= $d ?>" role="tab" aria-selected="<?= $d === 0 ? 'true' : 'false' ?>" aria-label="Show image <?= $d + 1 ?>"></button>
                <?php endfor; ?>
            </div>
            <button type="button" class="hero-nav-btn" data-hero-next aria-label="Next image">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="hero-overlay"></div>
    <div class="hero-grid">
        <div class="hero-copy">
            <p class="eyebrow hero-kicker">Farm management made clear</p>
            <h1>Run the estate with the same clarity as a modern enterprise.</h1>
            <p class="lede">FarmWise helps farmers organise daily operations — from fields and livestock to labour, inventory and finance — in one secure workspace built for owners, managers and the people who work the land.</p>
            <div class="hero-actions">
                <a class="btn btn-gold" href="<?= e(url('register')) ?>">Get started</a>
                <a class="btn btn-ghost" href="<?= e(url('login')) ?>">Sign in</a>
            </div>
            <ul class="hero-meta">
                <li>Secure sign-in</li>
                <li>Live farm overview</li>
                <li>Practical guidance</li>
            </ul>
        </div>
        <aside class="glass-dash" aria-label="Farm operations preview">
            <div class="glass-head">
                <div>
                    <p class="eyebrow">Golden Valley Estate</p>
                    <strong>Live operations</strong>
                </div>
                <span class="pulse">Synced</span>
            </div>
            <div class="glass-stats">
                <article><span>Crops in field</span><b>4</b><small>Active plantings</small></article>
                <article><span>Livestock</span><b>86</b><small>Animals on hand</small></article>
                <article><span>Production</span><b>18.6 t</b><small>Latest harvest</small></article>
                <article><span>Revenue</span><b>K 27.8k</b><small>Posted this cycle</small></article>
            </div>
            <div class="glass-row">
                <div>
                    <span>Expected harvest</span>
                    <strong>North Ridge wheat · 70 days</strong>
                </div>
                <div>
                    <span>Irrigation</span>
                    <strong>Valley drip · tomorrow 05:30</strong>
                </div>
            </div>
        </aside>
    </div>
</section>

<section class="section landing-section cream" id="about">
    <div class="wrap landing-about">
        <div class="landing-about-copy">
            <p class="eyebrow">About FarmWise</p>
            <h2>A calmer way to organise the farm.</h2>
            <p>FarmWise is a farm management platform that helps you keep day-to-day farming information in one place. Whether you manage fields, livestock, labour or finances, you can sign in, set up your farm workspace, and track what matters without juggling spreadsheets and notebooks.</p>
            <p>It is built for commercial estates and family farms alike — so owners, managers and workers can share a clear picture of what is happening on the land.</p>
        </div>
        <figure class="landing-about-media">
            <img
                src="<?= e(asset('img/livestock-farm-hero.jpg')) ?>"
                srcset="<?= e(asset('img/livestock-farm-hero.jpg')) ?> 2048w"
                sizes="(max-width: 900px) 92vw, 520px"
                width="2048"
                height="1152"
                alt="Cattle grazing beside crop fields and farm buildings at golden hour"
                loading="eager"
                decoding="async"
            >
        </figure>
    </div>
</section>

<section class="section landing-section landing-section-alt" id="benefits">
    <div class="wrap">
        <p class="eyebrow">Benefits</p>
        <h2>Practical advantages for everyday farming.</h2>
        <div class="feature-grid landing-benefits">
            <article>
                <h3>Better organisation</h3>
                <p>Keep farm activities, records and people aligned in a single workspace instead of scattered notes and files.</p>
            </article>
            <article>
                <h3>Easier monitoring</h3>
                <p>See what is active across the estate — from plantings and animals to upcoming tasks — at a glance.</p>
            </article>
            <article>
                <h3>Useful farm information</h3>
                <p>Access weather context, operational previews and guided help so decisions are informed, not guessed.</p>
            </article>
        </div>
    </div>
</section>

<section class="section landing-section cream" id="how-it-works">
    <div class="wrap landing-how">
        <div class="landing-how-copy">
            <p class="eyebrow">How it works</p>
            <h2>Three simple steps to get going.</h2>
            <ol class="landing-steps">
                <li>
                    <span class="landing-step-num">1</span>
                    <div>
                        <h3>Create an account or sign in</h3>
                        <p>Register a FarmWise workspace or sign in with your existing credentials.</p>
                    </div>
                </li>
                <li>
                    <span class="landing-step-num">2</span>
                    <div>
                        <h3>Set up your farm</h3>
                        <p>Add your farm details and enter the information that reflects how you operate.</p>
                    </div>
                </li>
                <li>
                    <span class="landing-step-num">3</span>
                    <div>
                        <h3>Manage and review</h3>
                        <p>Track farm activities day to day and review the information you need from your dashboard.</p>
                    </div>
                </li>
            </ol>
        </div>
        <figure class="landing-how-media">
            <img
                src="<?= e(asset('img/crops-farm-hero.jpg')) ?>"
                srcset="<?= e(asset('img/crops-farm-hero.jpg')) ?> 2400w"
                sizes="(max-width: 900px) 92vw, 520px"
                width="1200"
                height="800"
                alt="Close view of young green crops growing in dark soil"
                loading="lazy"
                decoding="async"
            >
        </figure>
    </div>
</section>

<section class="section landing-section landing-cta" id="get-started">
    <div class="wrap landing-cta-inner">
        <p class="eyebrow">Ready when you are</p>
        <h2>Start organising your farm with FarmWise.</h2>
        <p>Create a workspace in minutes and bring your farm operations into one clear place.</p>
        <div class="hero-actions">
            <a class="btn btn-gold" href="<?= e(url('register')) ?>">Get started</a>
            <a class="btn btn-ghost landing-cta-ghost" href="<?= e(url('login')) ?>">Sign in</a>
        </div>
    </div>
</section>

<footer class="landing-footer">
    <div class="landing-footer-grid wrap">
        <div class="landing-footer-brand">
            <a class="landing-footer-logo" href="<?= e(url()) ?>">
                <?= brand_logo('brand-logo brand-logo-footer') ?>
                <span>FarmWise</span>
            </a>
            <p>Helping farmers organise and manage farming operations with clarity and control.</p>
        </div>
        <div class="landing-footer-col">
            <h3>Explore</h3>
            <a href="<?= e(url()) ?>">Home</a>
            <a href="#about">About</a>
            <a href="#benefits">Benefits</a>
            <a href="#how-it-works">How it works</a>
        </div>
        <div class="landing-footer-col">
            <h3>Account</h3>
            <a href="<?= e(url('login')) ?>">Sign in</a>
            <a href="<?= e(url('register')) ?>">Get started</a>
        </div>
        <div class="landing-footer-col">
            <h3>Support</h3>
            <a href="#get-started">Get started guide</a>
            <button type="button" class="landing-footer-chat" data-open-ai>Ask FarmWise AI</button>
        </div>
    </div>
    <div class="landing-footer-bar wrap">
        <span>&copy; <?= date('Y') ?> FarmWise · Farm Management System</span>
    </div>
</footer>

<?php require dirname(__DIR__) . '/views/partials/assistant.php'; ?>
<script>window.IFMS_BASE = <?= json_encode(rtrim(url(''), '/')) ?>;</script>
<script src="<?= e(asset('js/app.js')) ?>?v=p6"></script>
<script src="<?= e(asset('js/assistant.js')) ?>?v=p6"></script>
</body>
</html>
