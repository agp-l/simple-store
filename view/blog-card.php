<?php
use SimpleStore\Content\ContentBody;
use SimpleStore\Media\MediaPath;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$cover = MediaPath::variant(ContentBody::cover($post['body'] ?? ''), 'card');
$postUrl = $siteRoot . $language . '/blog/' . rawurlencode($post['slug']);
$topic = ContentBody::topic($post['body'] ?? '');
$coverUrl = str_starts_with($cover, 'images/') ? $basePath . $cover : $cover;
?>
            <article class="blog-card">
              <a class="cms-post-image<?= $cover === '' ? ' blog-cover-placeholder' : '' ?>" href="<?= $escape($postUrl) ?>" aria-label="Otevřít článek <?= $escape($post['title']) ?>">
                <?php if ($cover !== ''): ?><img src="<?= $escape($coverUrl) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><?= \SimpleStore\Rendering\Icon::render('book') ?><span>Zápisky z cest</span><?php endif; ?>
              </a>
              <div class="blog-card-copy">
                <div class="blog-meta"><span class="blog-topic-label"><?= $escape(\SimpleStore\Content\BlogTopics::LABELS[$topic]) ?></span><span><?= \SimpleStore\Content\BlogTopics::minutes($post['body'] ?? '') ?> min čtení</span></div>
                <h2><a href="<?= $escape($postUrl) ?>"><?= $escape($post['title']) ?></a></h2>
                <p class="cms-post-summary"><?= $escape($post['summary'] ?? '') ?></p>
                <a class="blog-read" href="<?= $escape($postUrl) ?>">Přečíst příběh <?= \SimpleStore\Rendering\Icon::render('arrow-right') ?></a>
              <?php if ($canManageContent): ?><div class="inline-entry"><a href="<?= $escape($siteRoot . $language . '/blog/' . rawurlencode($post['slug']) . '?edit=1') ?>">✎ Upravit článek</a><?php $deletablePost = $post; require __DIR__ . '/blog-delete.php'; ?></div><?php endif; ?>
              </div>
            </article>
