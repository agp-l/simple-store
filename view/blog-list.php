  <main class="wrap cms-content" id="produkty">
    <?php if ($canManageContent && ($_GET['deleted'] ?? '') === '1'): ?><p class="catalog-manage-notice" role="status">Článek byl odstraněn.</p><?php endif; ?>
    <nav class="breadcrumbs" aria-label="Drobečková navigace"><a href="<?= $siteRoot . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Úvod</a><span aria-hidden="true">/</span><span>Blog</span></nav>
    <div class="cms-article">
      <p class="cms-eyebrow">Příběhy na cestu</p>
      <h1>Blog</h1>
      <?php if ($canManageContent): ?>
        <div class="inline-toolbar">
          <div><strong>Správa blogu</strong><p>Články upravíš přímo na jejich stránce.</p></div>
          <form method="post" action="<?= htmlspecialchars($siteRoot . 'admin.php', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create-content">
            <input type="hidden" name="type" value="post">
            <input type="hidden" name="language" value="<?= htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($adminCsrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <button type="submit" class="inline-small">＋ Nový článek</button>
          </form>
        </div>
        <?php if ($draftPosts !== []): ?>
          <section class="inline-history" aria-labelledby="draft-posts-title">
            <h2 id="draft-posts-title">Rozepsané články</h2>
            <p>Tyto články vidíš jen jako přihlášený správce. Návštěvníkům se nezobrazují.</p>
            <?php foreach ($draftPosts as $draft): ?>
              <div class="inline-revision">
                <span><?= htmlspecialchars($draft['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <a class="inline-small" href="<?= htmlspecialchars($siteRoot . $language . '/blog/' . rawurlencode($draft['slug']) . '?edit=1', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Upravit koncept</a>
              </div>
            <?php endforeach; ?>
            <?php if ($draftNextUrl !== ''): ?><p><a href="<?= htmlspecialchars($draftNextUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Další koncepty →</a></p><?php endif; ?>
          </section>
        <?php endif; ?>
      <?php endif; ?>
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
