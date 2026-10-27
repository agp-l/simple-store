  <main class="wrap cms-content blog-magazine" id="produkty">
    <?php if ($canManageContent && ($_GET['deleted'] ?? '') === '1'): ?><p class="catalog-manage-notice" role="status">Článek byl odstraněn.</p><?php endif; ?>
    <?php if ($canManageContent): ?><?php require __DIR__ . '/manage/blog-panel.php'; ?><?php endif; ?>
    <nav class="breadcrumbs" aria-label="Drobečková navigace"><a href="<?= $siteRoot . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Úvod</a><span aria-hidden="true">/</span><span>Blog</span></nav>
    <div class="blog-layout">
      <header class="blog-intro">
        <p class="cms-eyebrow">Zápisky dobrodruhů</p>
        <h1>Venku je o čem vyprávět.</h1>
        <p>Cesty, spaní pod širákem i vybavení, které dostalo zabrat. Příběhy z vlastní zkušenosti pro tvoje další dobrodružství.</p>
        <span class="blog-intro-note">Méně věcí. Více zážitků.</span>
      </header>
      <?php $blogRoot = $basePath.$language.'/blog'; $blogFilterUrl = static fn(?string $topic): string => $blogRoot . (($query = http_build_query(['topic'=>$topic, 'manage'=>$managingBlog ? '1' : null])) !== '' ? '?'.$query : '') . '#clanky'; ?>
      <nav class="blog-topics" aria-label="Kategorie blogu" id="clanky">
        <a href="<?= htmlspecialchars($blogFilterUrl(null), ENT_QUOTES, 'UTF-8') ?>" <?= $blogTopic === null ? 'aria-current="page"' : '' ?>>Všechny příběhy <span><?= array_sum($blogTopicCounts) ?></span></a>
        <?php foreach (\SimpleStore\Content\BlogTopics::LABELS as $topicKey=>$topicLabel): if (($blogTopicCounts[$topicKey] ?? 0) < 1 && $blogTopic !== $topicKey) continue; ?>
        <a href="<?= htmlspecialchars($blogFilterUrl($topicKey), ENT_QUOTES, 'UTF-8') ?>" <?= $blogTopic === $topicKey ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($topicLabel, ENT_QUOTES, 'UTF-8') ?> <span><?= (int)($blogTopicCounts[$topicKey] ?? 0) ?></span></a>
        <?php endforeach; ?>
      </nav>
      <?php if ($canManageContent): ?>

        <?php if ($draftPosts !== []): ?>
          <section class="inline-history" aria-labelledby="draft-posts-title">
            <h2 id="draft-posts-title">Rozepsané články</h2>
            <p>Tyto články vidíš jen jako přihlášený správce. Návštěvníkům se nezobrazují.</p>
            <?php foreach ($draftPosts as $draft): ?>
              <div class="inline-revision">
                <span><?= htmlspecialchars($draft['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <a class="inline-small" href="<?= htmlspecialchars($siteRoot . $language . '/blog/' . rawurlencode($draft['slug']) . '?edit=1', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Upravit koncept</a>
                <?php $deletablePost = $draft; $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); require __DIR__ . '/blog-delete.php'; ?>
              </div>
            <?php endforeach; ?>
            <?php if ($draftNextUrl !== ''): ?><p><a href="<?= htmlspecialchars($draftNextUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Další koncepty →</a></p><?php endif; ?>
          </section>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($posts === []): ?>
        <p class="cms-lead">V této kategorii zatím nejsou zveřejněné články.</p>
      <?php else: ?>
        <div class="cms-post-list blog-magazine-list">
          <?php foreach ($posts as $post): require __DIR__ . '/blog-card.php'; endforeach; ?>
        </div>
        <?php if ($nextUrl !== ''): ?><div class="load-more-wrap"><a class="load-more" data-load-more data-target="cms-post-list" href="<?= htmlspecialchars($nextUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Načíst další články</a></div><?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
