<?php
use SimpleStore\Content\ContentBody;
use SimpleStore\Media\MediaPath;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$cover = '';
foreach (ContentBody::decode($post['body'] ?? '') as $block) {
    if ($block['type'] === 'image') {
        $cover = MediaPath::variant($block['body'], 'card');
        break;
    }
}
$coverUrl = str_starts_with($cover, 'images/') ? $basePath . $cover : $cover;
?>
            <article>
              <?php if ($cover !== ''): ?><a class="cms-post-image" href="<?= $escape($siteRoot . $language . '/blog/' . rawurlencode($post['slug'])) ?>" aria-label="Otevřít článek <?= $escape($post['title']) ?>"><img src="<?= $escape($coverUrl) ?>" alt="" loading="lazy" decoding="async"></a><?php endif; ?>
              <time datetime="<?= $escape(str_replace(' ', 'T', $post['saved_at'])) ?>"><?= $escape(date('j. n. Y', strtotime($post['saved_at']))) ?></time>
              <h2><a href="<?= $escape($siteRoot . $language . '/blog/' . rawurlencode($post['slug'])) ?>"><?= $escape($post['title']) ?></a></h2>
              <p><?= $escape($post['summary'] ?? '') ?></p>
            </article>
