<?php
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
            <article>
              <time datetime="<?= $escape(str_replace(' ', 'T', $post['saved_at'])) ?>"><?= $escape(date('j. n. Y', strtotime($post['saved_at']))) ?></time>
              <h2><a href="<?= $escape($siteRoot . $language . '/blog/' . rawurlencode($post['slug'])) ?>"><?= $escape($post['title']) ?></a></h2>
              <p><?= $escape($post['summary'] ?? '') ?></p>
            </article>
