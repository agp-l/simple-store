  <main class="wrap cms-content" id="produkty">
    <nav class="breadcrumbs" aria-label="Drobečková navigace"><a href="<?= $siteRoot . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Úvod</a><span aria-hidden="true">/</span><span>Blog</span></nav>
    <div class="cms-article">
      <p class="cms-eyebrow">Příběhy na cestu</p>
      <h1>Blog</h1>
      <?php if ($posts === []): ?>
        <p class="cms-lead">Zatím zde nejsou zveřejněné články.</p>
      <?php else: ?>
        <div class="cms-post-list">
          <?php foreach ($posts as $post): require __DIR__ . '/blog-card.php'; endforeach; ?>
        </div>
        <?php if ($nextUrl !== ''): ?><div class="load-more-wrap"><a class="load-more" data-load-more data-target="cms-post-list" href="<?= htmlspecialchars($nextUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Načíst další články</a></div><?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
