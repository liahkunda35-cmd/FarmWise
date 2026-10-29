<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= brand_head() ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/fonts/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=p19">
    <link rel="preload" as="image" type="image/jpeg" href="<?= e(asset('img/farmwise-hero-laptop.jpg')) ?>?v=p17" fetchpriority="high">
    <link rel="preload" as="image" type="image/jpeg" href="<?= e(asset('img/livestock-farm-hero.jpg')) ?>">
    <script>document.documentElement.classList.add('js-motion');</script>
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
    $heroSlides = fe_public_config('hero_images');
    if (!is_array($heroSlides) || !$heroSlides) {
        $heroSlides = [fe_public_config('hero_image')];
    }
    $headlineLine1 = 'Smarter Farming';
    $headlineLine2 = 'for a Brighter Future';
    $lede = 'FarmWise helps farmers organise daily operations — from fields and livestock to labour, inventory and finance — in one simple, secure workspace.';
    $heroAlts = [
        'Farm landscape at golden hour with a laptop showing a farm management dashboard',
        'Cattle grazing beside crop fields and farm buildings at golden hour',
        'Young green crops growing in dark soil',
        'Farm overview across fields and buildings',
    ];
    ?>
    <div class="hero-media hero-slideshow" data-hero-slideshow data-interval="10000" data-auto-only="1">
        <div class="hero-slides" aria-hidden="true">
            <?php foreach ($heroSlides as $i => $slide): ?>
                <?php
                $slideUrl = is_string($slide) && (str_starts_with($slide, 'http://') || str_starts_with($slide, 'https://'))
                    ? $slide
                    : asset((string) $slide);
                $alt = $heroAlts[$i] ?? 'FarmWise farm photography';
                $dims = [
                    0 => [1280, 720],
                    1 => [2048, 1152],
                    2 => [2400, 1600],
                    3 => [1280, 720],
                ];
                [$iw, $ih] = $dims[$i] ?? [1280, 720];
                ?>
                <div class="hero-slide<?= $i === 0 ? ' is-active' : '' ?>" data-slide-index="<?= (int) $i ?>">
                    <img
                        src="<?= e($slideUrl) ?><?= $i === 0 ? '?v=p17' : '' ?>"
                        alt="<?= e($alt) ?>"
                        width="<?= (int) $iw ?>"
                        height="<?= (int) $ih ?>"
                        decoding="<?= $i === 0 ? 'sync' : 'async' ?>"
                        <?= $i === 0 ? 'fetchpriority="high"' : ($i === 1 ? 'loading="eager"' : 'loading="lazy"') ?>
                        draggable="false"
                    >
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="hero-grid hero-grid-copy-only">
        <div class="hero-copy">
            <p class="eyebrow hero-kicker hero-enter" style="--enter-delay:.05s">Farm management made easy</p>
            <h1
                class="hero-headline"
                data-typewriter-dual
                data-line1="<?= e($headlineLine1) ?>"
                data-line2="<?= e($headlineLine2) ?>"
                aria-label="<?= e($headlineLine1 . ' ' . $headlineLine2) ?>"
            >
                <span class="hero-line hero-line-white"><span class="typewriter-text" data-type-line="1"></span></span>
                <span class="hero-line hero-line-green"><span class="typewriter-text" data-type-line="2"></span></span>
                <span class="typewriter-caret" aria-hidden="true"></span>
            </h1>
            <p class="lede hero-lede is-waiting" data-hero-fade><?= e($lede) ?></p>
            <div class="hero-actions hero-enter hero-enter-up is-waiting" style="--enter-delay:.05s" data-hero-fade>
                <a class="btn btn-hero-primary" href="<?= e(url('register')) ?>">Get started <span aria-hidden="true">→</span></a>
                <a class="btn btn-hero-secondary" href="<?= e(url('login')) ?>">Sign in</a>
            </div>
            <ul class="hero-highlights hero-enter hero-enter-up is-waiting" style="--enter-delay:.12s" data-hero-fade>
                <li>
                    <span class="hero-highlight-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 4 17 3.5 17 3.5s1 5.5-2.2 10.4"/><path d="M12 12c0 4 2 7 5 8"/></svg>
                    </span>
                    Better planning
                </li>
                <li>
                    <span class="hero-highlight-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 11c1.5-3 4-5 7-5s5.5 2 7 5"/><path d="M4 16h16"/><path d="M8 11v5"/><path d="M16 11v5"/><circle cx="9" cy="8" r="1"/><circle cx="15" cy="8" r="1"/></svg>
                    </span>
                    Healthy livestock
                </li>
                <li>
                    <span class="hero-highlight-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V9"/><path d="M10 19V5"/><path d="M16 19v-8"/><path d="M22 19V7"/></svg>
                    </span>
                    Higher productivity
                </li>
                <li>
                    <span class="hero-highlight-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </span>
                    Secure data
                </li>
            </ul>
        </div>
    </div>
</section>

<section class="section landing-section cream" id="about">
    <div class="wrap landing-about">
        <div class="landing-about-copy" data-reveal="left">
            <p class="eyebrow">About FarmWise</p>
            <h2>A calmer way to organise the farm.</h2>
            <p>FarmWise is a farm management platform that helps you keep day-to-day farming information in one place. Whether you manage fields, livestock, labour or finances, you can sign in, set up your farm workspace, and track what matters without juggling spreadsheets and notebooks.</p>
            <p>It is built for commercial estates and family farms alike — so owners, managers and workers can share a clear picture of what is happening on the land.</p>
        </div>
        <figure class="landing-about-media" data-reveal="right">
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
        <p class="eyebrow" data-reveal="up">Benefits</p>
        <h2 data-reveal="up">Practical advantages for everyday farming.</h2>
        <div class="feature-grid landing-benefits" data-reveal-stagger>
            <article class="benefit-card benefit-card-green">
                <span class="benefit-card-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20h16"/><path d="M8 20V10l4-4 4 4v10"/><path d="M10 14h4"/></svg>
                </span>
                <h3>Better organisation</h3>
                <p>Keep farm activities, records and people aligned in a single workspace instead of scattered notes and files.</p>
            </article>
            <article class="benefit-card benefit-card-brown">
                <span class="benefit-card-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.9 4.9 1.4 1.4"/><path d="m17.7 17.7 1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m4.9 19.1 1.4-1.4"/><path d="m17.7 6.3 1.4-1.4"/></svg>
                </span>
                <h3>Easier monitoring</h3>
                <p>See what is active across the estate — from plantings and animals to upcoming tasks — at a glance.</p>
            </article>
            <article class="benefit-card benefit-card-blue">
                <span class="benefit-card-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v3"/><path d="M12 18v3"/><path d="M5 8h14l-1.5 9.5a2 2 0 0 1-2 1.5H8.5a2 2 0 0 1-2-1.5L5 8Z"/><path d="M9 8c0-1.5 1.3-3 3-3s3 1.5 3 3"/></svg>
                </span>
                <h3>Useful farm information</h3>
                <p>Access weather context, operational previews and guided help so decisions are informed, not guessed.</p>
            </article>
        </div>
    </div>
</section>

<section class="section landing-section cream" id="how-it-works">
    <div class="wrap landing-how">
        <div class="landing-how-copy">
            <p class="eyebrow" data-reveal="up">How it works</p>
            <h2 data-reveal="right">Three simple steps to get going.</h2>
            <ol class="landing-steps" data-reveal-stagger>
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
        <figure class="landing-how-media" data-reveal="left">
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
    <div class="wrap landing-cta-inner" data-reveal="up">
        <p class="eyebrow">Ready when you are</p>
        <h2>Start organising your farm with FarmWise.</h2>
        <p>Create a workspace in minutes and bring your farm operations into one clear place.</p>
        <div class="hero-actions">
            <a class="btn btn-gold" href="<?= e(url('register')) ?>">Get started</a>
            <a class="btn btn-ghost landing-cta-ghost" href="<?= e(url('login')) ?>">Sign in</a>
        </div>
    </div>
</section>

<footer class="landing-footer" data-reveal-footer>
    <div class="landing-footer-grid wrap">
        <div class="landing-footer-brand" data-reveal="up">
            <a class="landing-footer-logo" href="<?= e(url()) ?>">
                <?= brand_logo('brand-logo brand-logo-footer') ?>
                <span>FarmWise</span>
            </a>
            <p>Helping farmers organise and manage farming operations with clarity and control.</p>
        </div>
        <div class="landing-footer-col" data-reveal="up">
            <h3>Explore</h3>
            <a href="<?= e(url()) ?>">Home</a>
            <a href="#about">About</a>
            <a href="#benefits">Benefits</a>
            <a href="#how-it-works">How it works</a>
        </div>
        <div class="landing-footer-col" data-reveal="up">
            <h3>Account</h3>
            <a href="<?= e(url('login')) ?>">Sign in</a>
            <a href="<?= e(url('register')) ?>">Get started</a>
        </div>
        <div class="landing-footer-col" data-reveal="up">
            <h3>Support</h3>
            <a href="#get-started">Get started guide</a>
            <button type="button" class="landing-footer-chat" data-open-ai>Ask FarmWise AI</button>
        </div>
    </div>
    <div class="landing-footer-bar wrap" data-reveal="up">
        <span>&copy; <?= date('Y') ?> FarmWise · Farm Management System</span>
    </div>
</footer>

<?php
$assistantMode = 'public';
fe_component('partials/assistant');
?>
<script>window.IFMS_BASE = <?= json_encode(rtrim(url(''), '/')) ?>;</script>
<script src="<?= e(url('frontend/config/api.js')) ?>?v=p1"></script>
<script src="<?= e(asset('js/app.js')) ?>?v=p15"></script>
<script src="<?= e(asset('js/assistant.js')) ?>?v=p12"></script>
</body>
</html>
