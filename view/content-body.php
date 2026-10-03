<?php
use SimpleStore\Content\ContentBody;
use SimpleStore\Product\ProductText;
use SimpleStore\Media\MediaPath;
use SimpleStore\Editing\CardSummary;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$editing = $contentEditMode;
$kind = $page === 'post' ? 'článek' : 'stránku';
$route = $language . ($page === 'post' ? '/blog' : '') . '/' . rawurlencode($content['slug']);
$sections = ContentBody::decode($content['body']);
$editable = static function (string $field, string $value, ?int $index = null, string $operation = 'set') use ($editing, $escape): string {
    if (!$editing) return '';
    return ' contenteditable="plaintext-only" spellcheck="true" role="textbox"'
        . ' data-edit-operation="' . $escape($operation) . '" data-edit-field="' . $escape($field) . '"'
        . ($index === null ? '' : ' data-edit-index="' . $index . '"')
        . ' data-edit-value="' . $escape($value) . '" aria-label="Kliknutím upravit ' . $escape($field) . '"';
};
?>
  <main class="wrap cms-content<?= $editing ? ' is-editing' : '' ?>" id="produkty">
    <?php if ($canEditContent && !$editing): ?>
      <p class="inline-entry"><a href="<?= $escape($siteRoot . $route . '?edit=1') ?>">✎ Upravit <?= $kind ?> přímo na stránce</a></p>
    <?php endif; ?>
    <?php if ($editing): ?>
      <div class="inline-toolbar">
        <div><strong><?= $page === 'post' ? 'Článek' : 'Stránka' ?> · <?= !empty($content['published']) ? 'publikováno' : 'koncept' ?></strong><p>Klikni do textu; úprava se uloží při opuštění pole.</p></div>
        <span class="inline-status" id="content-editor-status" role="status" aria-live="polite">Revize <?= (int) $content['revision_number'] ?></span>
        <button type="button" class="inline-small" data-content-action="publish" data-value="<?= $content['published'] ? '0' : '1' ?>"><?= $content['published'] ? 'Skrýt' : 'Publikovat' ?></button>
        <button type="button" class="inline-small" data-content-action="media-library">▧ Fotografie</button>
        <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
          <input type="hidden" name="action" value="create-content"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
          <input type="hidden" name="type" value="<?= $escape($page) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
          <button type="submit" class="inline-small">＋ <?= $page === 'post' ? 'Nový článek' : 'Nová stránka' ?></button>
        </form>
        <?php if ($content['published']): ?><a href="<?= $escape($siteRoot . $route) ?>">Zobrazit jako návštěvník ↗</a><?php endif; ?>
        <a href="<?= $escape($siteRoot . 'admin.php') ?>">Všechny stránky a články</a>
      </div>
      <div class="inline-settings" aria-label="Nastavení dokumentu">
        <span>Adresa: <span class="inline-slug"<?= $editable('slug', $content['slug']) ?>><?= $escape($content['slug']) ?></span></span>
        <?php if ($page === 'page'): ?>
          <label>Horní odkazy <select data-content-select="visible_in_menu"><option value="0" <?= !$content['visible_in_menu'] ? 'selected' : '' ?>>Nezobrazovat</option><option value="1" <?= $content['visible_in_menu'] ? 'selected' : '' ?>>Zobrazovat</option></select></label>
          <label>Pořadí v menu <input type="number" min="0" max="65535" value="<?= (int) $content['menu_order'] ?>" data-content-input="menu_order"></label>
        <?php endif; ?>
      </div>
      <details class="inline-delete-product"><summary>Odstranit <?= $page === 'post' ? 'článek' : 'stránku' ?></summary>
        <p>Smazání odstraní <?= $page === 'post' ? 'článek' : 'stránku' ?> a všechny revize v tomto jazyce. Ostatní překlady a nahrané fotografie zůstanou zachované.</p>
        <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
          <input type="hidden" name="action" value="delete-content"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
          <input type="hidden" name="key" value="<?= $escape($content['document_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
          <input type="hidden" name="type" value="<?= $escape($page) ?>"><input type="hidden" name="revision" value="<?= (int) $content['revision_number'] ?>">
          <label><input type="checkbox" name="confirm" value="1" required> Rozumím, že tento obsah a jeho revize už nepůjdou obnovit.</label>
          <button type="submit" class="inline-small">Smazat <?= $page === 'post' ? 'článek' : 'stránku' ?></button>
        </form>
      </details>
    <?php endif; ?>
    <nav class="breadcrumbs" aria-label="Drobečková navigace">
      <a href="<?= $siteRoot . $escape($language) ?>">Úvod</a><span aria-hidden="true">/</span>
      <?php if ($page === 'post'): ?>
        <a href="<?= $escape($backLink) ?>">Blog</a><span aria-hidden="true">/</span>
      <?php endif; ?>
      <span><?= $escape($content['title']) ?></span>
    </nav>
    <article class="cms-article">
      <p class="cms-eyebrow"><?= $page === 'post' ? 'Z blogu' : 'Stránka' ?></p>
      <h1<?= $editable('title', $content['title']) ?>><?= $escape($content['title']) ?></h1>
      <?php if ($editing || ($content['summary'] ?? '') !== ''): ?>
        <p class="cms-lead"<?= $editable('summary', $content['summary'] ?? '') ?><?= $editing && $page === 'post' ? ' data-edit-maxlength="' . CardSummary::MAX_CHARACTERS . '" aria-describedby="post-summary-hint"' : '' ?>><?= $escape($content['summary'] ?: 'Krátký úvod…') ?></p>
      <?php endif; ?>
      <?php if ($editing && $page === 'post'): ?><small class="inline-summary-hint" id="post-summary-hint">Perex článku: nejvýše <?= CardSummary::MAX_CHARACTERS ?> znaků; v přehledu maximálně tři řádky.</small><?php endif; ?>
      <div class="cms-text cms-blocks">
        <?php if ($editing): ?><div class="inline-insert"><button type="button" class="inline-small" data-content-action="choose-block">＋ Přidat blok</button><span class="inline-block-types" hidden><?php foreach (ContentBody::TYPES as $type => $label): ?><button type="button" class="inline-tiny" data-content-action="section-add" data-value="<?= $type ?>" data-index="-1"><?= $label ?></button><?php endforeach; ?></span></div><?php endif; ?>
        <?php foreach ($sections as $i => $section): ?>
          <section class="cms-block" data-section="<?= $i ?>">
            <?php if ($editing): ?><div class="inline-block-controls">
              <label>Typ <select data-content-type data-index="<?= $i ?>"><?php foreach (ContentBody::TYPES as $type => $label): ?><option value="<?= $type ?>" <?= $section['type'] === $type ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
              <button type="button" class="inline-tiny" data-content-action="section-up" data-index="<?= $i ?>" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Posunout blok nahoru">↑</button>
              <button type="button" class="inline-tiny" data-content-action="section-down" data-index="<?= $i ?>" <?= $i === count($sections) - 1 ? 'disabled' : '' ?> aria-label="Posunout blok dolů">↓</button>
              <button type="button" class="inline-tiny" data-content-action="section-remove" data-index="<?= $i ?>" aria-label="Odebrat blok">×</button>
            </div><?php endif; ?>
            <?php if ($editing || $section['heading'] !== ''): ?><h2 class="cms-block-title"<?= $editable('section_heading', $section['heading'], $i, 'section.set') ?>><?= $escape($section['heading'] ?: 'Nadpis bloku…') ?></h2><?php endif; ?>
            <?php if ($section['type'] === 'image'): ?>
              <?php $fullImage = str_starts_with($section['body'], 'images/') ? $basePath . $section['body'] : $section['body']; $cardPath = MediaPath::variant($section['body'], 'card'); $cardImage = str_starts_with($cardPath, 'images/') ? $basePath . $cardPath : $cardPath; ?>
              <figure class="cms-block-image"><img src="<?= $escape($cardImage) ?>" <?= MediaPath::isManaged($section['body']) ? 'srcset="' . $escape($cardImage) . ' 960w, ' . $escape($fullImage) . ' 1800w" sizes="(max-width: 760px) 100vw, 850px"' : '' ?> alt="<?= $escape($section['heading'] ?: $content['title']) ?>" loading="lazy"></figure>
              <?php if ($editing): ?><button type="button" class="inline-small" data-content-action="section-image" data-index="<?= $i ?>" data-value="<?= $escape($section['body']) ?>">✎ Změnit fotografii</button><?php endif; ?>
            <?php else: ?>
              <div class="cms-block-text"<?= $editable('section_body', $section['body'], $i, 'section.set') ?>><?php
                if ($section['type'] === 'list'): ?><ul class="feature-list"><?php
                  foreach (preg_split('/\R/u', $section['body']) ?: [] as $line):
                    if (trim($line) !== ''): ?><li><?= ProductText::inline(trim($line)) ?></li><?php endif;
                  endforeach; ?></ul><?php
                elseif ($section['type'] === 'table'): ?><div class="table-scroll"><table class="spec-table"><tbody><?php
                  foreach (preg_split('/\R/u', $section['body']) ?: [] as $line):
                    if (trim($line) !== ''):
                      [$label, $value] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
                      ?><tr><th scope="row"><?= $escape($label) ?></th><td><?= ProductText::inline($value) ?></td></tr><?php
                    endif;
                  endforeach; ?></tbody></table></div><?php
                else:
                  foreach (preg_split('/\R\s*\R/u', $section['body']) ?: [] as $paragraph):
                    if (trim($paragraph) !== ''): ?><p><?= nl2br(ProductText::inline(trim($paragraph))) ?></p><?php endif;
                  endforeach;
                endif; ?></div>
            <?php endif; ?>
          </section>
          <?php if ($editing): ?><div class="inline-insert"><button type="button" class="inline-small" data-content-action="choose-block">＋ Přidat blok</button><span class="inline-block-types" hidden><?php foreach (ContentBody::TYPES as $type => $label): ?><button type="button" class="inline-tiny" data-content-action="section-add" data-value="<?= $type ?>" data-index="<?= $i ?>"><?= $label ?></button><?php endforeach; ?></span></div><?php endif; ?>
        <?php endforeach; ?>
      </div>
    </article>
    <?php if ($editing && $contentHistory !== []): ?><details class="inline-history"><summary>Historie úprav (<?= count($contentHistory) ?>)</summary><p>Obnovení starší verze vytvoří další revizi. Předchozí verze zůstanou uložené.</p>
      <?php foreach ($contentHistory as $revision): ?><div class="inline-revision"><span>Revize <?= (int) $revision['revision_number'] ?> · <?= $escape($revision['saved_at']) ?> · <?= $escape($revision['title']) ?></span>
        <?php if ($revision['active_document_key'] === null): ?><button class="inline-small" type="button" data-content-action="restore" data-value="<?= (int) $revision['revision_number'] ?>">Obnovit jako novou revizi</button><?php else: ?><small>Aktuální</small><?php endif; ?>
      </div><?php endforeach; ?></details><?php endif; ?>
    <?php if ($editing): ?><script type="application/json" id="content-editor-config"><?= json_encode([
        'endpoint' => $basePath . 'admin.php', 'key' => $content['document_key'],
        'type' => $page, 'language' => $language, 'revision' => (int) $content['revision_number'],
        'csrf' => $editToken,
      ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
      <?php require __DIR__ . '/media/picker.php'; ?><?php endif; ?>
  </main>
