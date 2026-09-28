  <main class="wrap cms-content" id="produkty">
    <nav class="breadcrumbs" aria-label="Drobečková navigace"><a href="<?= $siteRoot . htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Úvod</a><span aria-hidden="true">/</span><span>Blog</span></nav>
    <div class="cms-article">
      <p class="cms-eyebrow">Příběhy na cestu</p>
      <h1>Blog</h1>
      <?php if ($posts === []): ?>
        <p class="cms-lead">Zatím zde nejsou zveřejněné články.</p>
      <?php else: ?>
        <div class="cms-post-list">
          <?php foreach ($posts as $post): ?>
            <article>
              <time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $post['saved_at']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('j. n. Y', strtotime($post['saved_at'])), ENT_QUOTES, 'UTF-8') ?></time>
              <h2><a href="<?= $siteRoot . $language ?>/blog/<?= rawurlencode($post['slug']) ?>"><?= htmlspecialchars($post['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></h2>
              <p><?= htmlspecialchars($post['summary'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
