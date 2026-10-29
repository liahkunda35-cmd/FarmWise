<section class="panel">
    <header>
        <h3>Search<?= $q !== '' ? ': ' . e($q) : '' ?></h3>
    </header>
    <form class="search-form" method="get" action="<?= e(url('search')) ?>">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search farms, fields, crops, or tasks" minlength="2" required>
        <button class="btn btn-gold" type="submit">Search</button>
    </form>
    <?php if ($q !== '' && mb_strlen($q) < 2): ?>
        <p class="muted">Type at least two characters.</p>
    <?php elseif ($q !== '' && !$results): ?>
        <p class="muted">No matching farms, fields, plantings or tasks were found.</p>
    <?php elseif ($results): ?>
        <ul class="feed">
            <?php foreach ($results as $row): ?>
                <li>
                    <a class="feed-link" href="<?= e($row['href']) ?>">
                        <strong><?= e($row['label']) ?></strong>
                        <span><?= e($row['meta']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted">Search across farms, fields, plantings and tasks.</p>
    <?php endif; ?>
</section>
