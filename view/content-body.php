  <main class="wrap cms-content" id="produkty">
    <nav class="breadcrumbs" aria-label="Drobečková navigace">
      <a href="<?= $siteRoot ?>index.php">Úvod</a><span aria-hidden="true">/</span>
      <?php if ($page === 'post'): ?>
        <a href="<?= htmlspecialchars($backLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Blog</a><span aria-hidden="true">/</span>
      <?php endif; ?>
      <span><?= htmlspecialchars($content['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
    </nav>
    <article class="cms-article">
      <p class="cms-eyebrow"><?= $page === 'post' ? 'Z blogu' : 'Stránka' ?></p>
      <h1><?= htmlspecialchars($content['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <?php if (($content['summary'] ?? '') !== ''): ?>
        <p class="cms-lead"><?= htmlspecialchars($content['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <?php endif; ?>
      <div class="cms-text"><?= nl2br(htmlspecialchars($content['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
    </article>
  </main>
