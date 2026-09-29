<?php
use SimpleStore\Product\ProductDetails;
use SimpleStore\Category\CategoryPath;
use SimpleStore\Product\ProductText;
use SimpleStore\Media\MediaPath;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$editing = (bool) ($editMode ?? false);
$canEdit = (bool) ($canEditProduct ?? false);
$imageUrl = static fn (string $path): string => str_starts_with($path, 'images/') ? $basePath . $path : $path;
$image = $imageUrl($product['image_path']);
$mediaLibraryUrl = $basePath . 'admin.php?' . http_build_query([
    'section' => 'media', 'type' => 'product', 'key' => $product['product_key'], 'language' => $language,
]);
$details = ProductDetails::decode($product['details_json'] ?? null, $product['sizes'] ?? '');
$categoryPath = CategoryPath::fromProduct($product);
$categoryLabel = $categoryLabels[$categoryPath] ?? $categoryLabels[explode('/', $categoryPath)[0]] ?? 'Vybavení';
$stockText = ['in_stock' => 'Skladem', 'on_order' => 'Na objednávku', 'out_of_stock' => 'Není skladem'];
$editable = static function (string $field, string $value, ?int $index = null, string $operation = 'set') use ($editing, $escape): string {
    if (!$editing) return '';
    return ' contenteditable="plaintext-only" spellcheck="true" role="textbox"'
        . ' data-edit-operation="' . $escape($operation) . '" data-edit-field="' . $escape($field) . '"'
        . ($index === null ? '' : ' data-edit-index="' . $index . '"')
        . ' data-edit-value="' . $escape($value) . '" aria-label="Kliknutím upravit ' . $escape($field) . '"';
};
?>
  <main class="wrap detail-page<?= $editing ? ' is-editing' : '' ?>" id="produkty">
    <?php if ($canEdit && !$editing): ?>
      <p class="inline-entry"><a href="<?= $escape($siteRoot . $language . '/produkt/' . rawurlencode($product['slug']) . '?edit=1') ?>">✎ Upravit tento produkt přímo na stránce</a></p>
    <?php endif; ?>
    <?php if ($editing): ?>
      <div class="inline-toolbar" id="inline-toolbar">
        <div><strong>Upravuješ <?= !empty($product['published']) ? 'veřejný produkt' : 'neveřejný koncept' ?></strong><p>Klikni do textu; úprava se uloží při opuštění pole.</p></div>
        <span class="inline-status" id="inline-status" role="status" aria-live="polite">Revize <?= (int) $product['revision_number'] ?></span>
        <button type="button" class="inline-small" data-editor-action="publish" data-value="<?= $product['published'] ? '0' : '1' ?>"><?= $product['published'] ? 'Skrýt produkt' : 'Publikovat produkt' ?></button>
        <button type="button" class="inline-small" data-editor-action="media-library">▧ Fotografie</button>
        <a href="<?= $escape($mediaLibraryUrl) ?>">Knihovna tohoto produktu ↗</a>
        <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>" class="inline-create-product"><input type="hidden" name="action" value="create-product"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><button type="submit" class="inline-small">＋ Nový produkt</button></form>
        <?php if ($product['published']): ?><a href="<?= $escape($siteRoot . $language . '/produkt/' . rawurlencode($product['slug'])) ?>">Zobrazit jako návštěvník ↗</a><?php endif; ?>
        <a href="<?= $escape($siteRoot . $language . '?manage=1') ?>">Všechny produkty včetně skrytých</a>
      </div>
      <div class="inline-settings" aria-label="Nastavení produktu">
        <label>Kategorie <select data-editor-select="category_path">
          <?php foreach ($editorCategories as $option): ?><option value="<?= $escape($option['path']) ?>" <?= $categoryPath === $option['path'] ? 'selected' : '' ?>><?= $escape($option['title']) ?></option><?php endforeach; ?>
        </select></label>
        <label>Dostupnost <select data-editor-select="stock_status">
          <?php foreach ($stockText as $code => $text): ?><option value="<?= $code ?>" <?= $product['stock_status'] === $code ? 'selected' : '' ?>><?= $escape($text) ?></option><?php endforeach; ?>
        </select></label>
        <span>Adresa: <span class="inline-slug"<?= $editable('slug', $product['slug']) ?>><?= $escape($product['slug']) ?></span></span>
      </div>
      <details class="inline-delete-product"><summary>Odstranit produkt</summary>
        <p>Smazání odstraní produkt a všechny jeho revize v tomto jazyce. Nahrané obrázky zůstanou na disku, protože mohou mít zkopírované odkazy.</p>
        <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
          <input type="hidden" name="action" value="delete-product"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
          <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
          <input type="hidden" name="revision" value="<?= (int) $product['revision_number'] ?>">
          <label><input type="checkbox" name="confirm" value="1" required> Rozumím, že revize produktu už nepůjdou obnovit.</label>
          <button type="submit" class="inline-small">Smazat produkt</button>
        </form>
      </details>
    <?php endif; ?>
    <nav class="breadcrumbs" aria-label="Drobečková navigace"><a href="<?= $escape($siteRoot . $language) ?>">Úvod</a>
      <?php foreach ($categoryTrail as $crumb): ?><span>/</span><a href="<?= $escape($siteRoot . $language . '/kategorie-produktu/' . $crumb['path']) ?>"><?= $escape($crumb['title']) ?></a><?php endforeach; ?>
      <span>/</span><span><?= $escape($product['name']) ?></span></nav>
    <div class="product-detail">
      <div class="detail-media">
        <div class="detail-gallery<?= $product['category'] === 'boty' ? ' detail-gallery--footwear' : '' ?>"><button type="button" class="detail-gallery-open" id="detail-image-open" aria-label="Zvětšit fotografii produktu"><img id="detail-image" src="<?= $escape($image) ?>" data-image-path="<?= $escape($product['image_path']) ?>" alt="<?= $escape($product['name']) ?>" width="1200" height="1200"><span class="detail-zoom-hint" aria-hidden="true">⤢ Zvětšit</span></button></div>
        <?php if ($editing): ?><div class="inline-image-actions"><button type="button" class="inline-small" data-editor-action="main-image">✎ Hlavní obrázek</button><button type="button" class="inline-small" data-editor-action="gallery-add">＋ Přidat fotografii</button></div><?php endif; ?>
        <?php if ($details['gallery'] !== []): ?><div class="detail-thumbs" aria-label="Fotografie produktu">
          <?php foreach (array_merge([$product['image_path']], $details['gallery']) as $i => $path): ?>
            <span class="inline-thumb"><button type="button" class="detail-thumb" data-gallery-image="<?= $escape($imageUrl($path)) ?>" aria-label="Zobrazit fotografii <?= $i + 1 ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><img src="<?= $escape($imageUrl(MediaPath::variant($path, 'thumb'))) ?>" alt="" loading="lazy"></button>
              <?php if ($editing && $i > 0): ?><button type="button" class="inline-tiny" data-editor-action="gallery-set" data-editor-index="<?= $i - 1 ?>" data-editor-value="<?= $escape($path) ?>" aria-label="Upravit fotografii <?= $i + 1 ?>">✎</button><button type="button" class="inline-tiny" data-editor-action="gallery-remove" data-editor-index="<?= $i - 1 ?>" aria-label="Odebrat fotografii <?= $i + 1 ?>">×</button><?php endif; ?>
            </span>
          <?php endforeach; ?></div><?php endif; ?>
        <dialog class="detail-lightbox" id="detail-lightbox" aria-label="Zvětšená fotografie produktu"><button type="button" class="detail-lightbox-close" id="detail-lightbox-close" aria-label="Zavřít zvětšenou fotografii">× Zavřít</button><img id="detail-lightbox-image" alt="<?= $escape($product['name']) ?>"></dialog>
      </div>
      <div class="detail-info">
        <p class="product-brand"><span<?= $editable('brand', $product['brand']) ?>><?= $escape($product['brand']) ?></span> · <?= $escape($categoryLabel) ?></p>
        <h1<?= $editable('name', $product['name']) ?>><?= $escape($product['name']) ?></h1>
        <?php if ($editing || $product['summary'] !== ''): ?><p class="detail-lead"<?= $editable('summary', $product['summary'] ?? '') ?>><?= $escape($product['summary'] ?: 'Krátký popis produktu…') ?></p><?php endif; ?>
        <form method="post" action="<?= $escape($cartUrl) ?>" class="product-purchase">
          <input type="hidden" name="action" value="add"><input type="hidden" name="csrf" value="<?= $escape($cartToken) ?>">
          <input type="hidden" name="product_key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
        <?php foreach ($details['options'] as $i => $group): ?>
          <div class="inline-option">
            <label class="field-label" for="product-option-<?= $i ?>"><span<?= $editable('option_name', $group['name'], $i, 'option.set') ?>><?= $escape($group['name']) ?></span></label>
            <select id="product-option-<?= $i ?>" name="options[<?= $i ?>]" class="product-option" required><option value="">Vyberte <?= $escape($group['name']) ?></option>
              <?php foreach ($group['values'] as $choice): ?><option value="<?= $escape($choice) ?>"><?= $escape($choice) ?></option><?php endforeach; ?>
            </select>
            <?php if ($editing): ?><div class="inline-option-tools"><button class="inline-tiny" type="button" data-editor-action="option-values" data-editor-index="<?= $i ?>">✎ Upravit možnosti</button><button class="inline-tiny" type="button" data-editor-action="option-remove" data-editor-index="<?= $i ?>">× Odebrat</button></div>
              <div class="inline-choices" hidden<?= $editable('option_values', implode("\n", $group['values']), $i, 'option.set') ?>><?= $escape(implode("\n", $group['values'])) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($editing): ?><button class="inline-small" type="button" data-editor-action="option-add">＋ Přidat výběr (barva, velikost…)</button><?php endif; ?>
        <div class="detail-price"><strong><span<?= $editable('price_czk', (string) $product['price_czk']) ?>><?= number_format((int) $product['price_czk'], 0, ',', ' ') ?></span> Kč</strong><span class="stock <?= $product['stock_status'] === 'in_stock' ? '' : 'stock-wait' ?>"><span class="stock-dot" aria-hidden="true"></span><?= $escape($stockText[$product['stock_status']] ?? '') ?></span></div>
        <div class="buy-row"><div class="quantity"><button type="button" id="minus" aria-label="Ubrat kus">−</button><input id="qty" type="number" name="quantity" min="1" max="99" value="1" aria-label="Počet kusů" required><button type="button" id="plus" aria-label="Přidat kus">＋</button></div>
          <button class="detail-add" type="submit" id="detail-add" <?= $product['stock_status'] === 'out_of_stock' || empty($product['published']) ? 'disabled' : '' ?>><?= empty($product['published']) ? 'Neveřejný koncept' : ($product['stock_status'] === 'out_of_stock' ? 'Není skladem' : 'Přidat do košíku') ?></button></div>
        </form>
      </div>
    </div>
    <?php if ($editing || $product['description'] !== '' || $details['sections'] !== [] || $details['specifications'] !== []): ?>
      <div class="product-extra<?= !$editing && $product['description'] === '' && $details['sections'] === [] ? ' specs-only' : '' ?>">
        <?php if ($editing || $product['description'] !== '' || $details['sections'] !== []): ?><section class="story" aria-label="Popis produktu"><h2>O produktu</h2>
          <?php if ($editing || $product['description'] !== ''): ?><div class="cms-text product-intro"<?= $editable('description', $product['description']) ?>><?= nl2br($escape($product['description'] ?: 'Úvodní text produktu…')) ?></div><?php endif; ?>
          <?php if ($editing): ?>
            <div class="inline-insert"><button type="button" class="inline-small" data-editor-action="choose-block">＋ Přidat blok</button><span class="inline-block-types" hidden><?php foreach (['text' => 'Text', 'list' => 'Seznam', 'table' => 'Tabulka', 'image' => 'Fotografie'] as $type => $title): ?><button type="button" class="inline-tiny" data-editor-action="section-add" data-editor-value="<?= $type ?>" data-editor-index="-1"><?= $title ?></button><?php endforeach; ?></span></div>
          <?php endif; ?>
          <?php foreach ($details['sections'] as $i => $section): ?>
            <section class="product-content-block" data-section="<?= $i ?>">
              <?php if ($editing): ?><div class="inline-block-controls">
                <label>Typ <select data-editor-type="section" data-editor-index="<?= $i ?>"><?php foreach (['text' => 'Text', 'list' => 'Seznam', 'table' => 'Tabulka', 'image' => 'Fotografie'] as $type => $title): ?><option value="<?= $type ?>" <?= $section['type'] === $type ? 'selected' : '' ?>><?= $title ?></option><?php endforeach; ?></select></label>
                <button type="button" class="inline-tiny" data-editor-action="section-up" data-editor-index="<?= $i ?>" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Posunout blok nahoru">↑</button>
                <button type="button" class="inline-tiny" data-editor-action="section-down" data-editor-index="<?= $i ?>" <?= $i === count($details['sections']) - 1 ? 'disabled' : '' ?> aria-label="Posunout blok dolů">↓</button>
                <button type="button" class="inline-tiny" data-editor-action="section-remove" data-editor-index="<?= $i ?>" aria-label="Odebrat blok">×</button>
              </div><?php endif; ?>
              <?php if ($editing || $section['heading'] !== ''): ?><h3<?= $editable('section_heading', $section['heading'], $i, 'section.set') ?>><?= $escape($section['heading'] ?: 'Nadpis bloku…') ?></h3><?php endif; ?>
              <?php if ($section['type'] === 'image'): ?>
                <figure class="product-story-image"><img src="<?= $escape($imageUrl(MediaPath::variant($section['body'], 'card'))) ?>" <?= MediaPath::isManaged($section['body']) ? 'srcset="' . $escape($imageUrl(MediaPath::variant($section['body'], 'card'))) . ' 960w, ' . $escape($imageUrl($section['body'])) . ' 1800w" sizes="(max-width: 760px) 100vw, 800px"' : '' ?> alt="<?= $escape($section['heading'] !== '' ? $section['heading'] : $product['name']) ?>" loading="lazy"></figure>
                <?php if ($editing): ?><button type="button" class="inline-small" data-editor-action="section-image" data-editor-index="<?= $i ?>" data-editor-value="<?= $escape($section['body']) ?>">✎ Změnit fotografii v bloku</button><?php endif; ?>
              <?php else: ?><div class="inline-block-text"<?= $editable('section_body', $section['body'], $i, 'section.set') ?>>
                <?php if ($section['type'] === 'list'): ?><ul class="feature-list">
                  <?php foreach (preg_split('/\R/u', $section['body']) ?: [] as $line): ?><?php if (trim($line) !== ''): ?><li><?= ProductText::inline(trim($line)) ?></li><?php endif; ?><?php endforeach; ?>
                </ul>
                <?php elseif ($section['type'] === 'table'): ?><div class="table-scroll"><table class="spec-table"><tbody>
                  <?php foreach (preg_split('/\R/u', $section['body']) ?: [] as $line): ?><?php if (trim($line) !== ''): ?><?php [$label, $value] = array_pad(array_map('trim', explode('|', $line, 2)), 2, ''); ?><tr><th scope="row"><?= $escape($label) ?></th><td><?= ProductText::inline($value) ?></td></tr><?php endif; ?><?php endforeach; ?>
                </tbody></table></div>
                <?php else: ?><?php foreach (preg_split('/\R\s*\R/u', $section['body']) ?: [] as $paragraph): ?><p><?= nl2br(ProductText::inline(trim($paragraph))) ?></p><?php endforeach; ?><?php endif; ?>
              </div><?php endif; ?>
            </section>
            <?php if ($editing): ?><div class="inline-insert"><button type="button" class="inline-small" data-editor-action="choose-block">＋ Přidat blok</button><span class="inline-block-types" hidden><?php foreach (['text' => 'Text', 'list' => 'Seznam', 'table' => 'Tabulka', 'image' => 'Fotografie'] as $type => $title): ?><button type="button" class="inline-tiny" data-editor-action="section-add" data-editor-value="<?= $type ?>" data-editor-index="<?= $i ?>"><?= $title ?></button><?php endforeach; ?></span></div><?php endif; ?>
          <?php endforeach; ?>
        </section><?php endif; ?>
        <?php if ($editing || $details['specifications'] !== []): ?><aside class="product-specs"><h2>Technické údaje</h2><div class="table-scroll"><table class="spec-table"><tbody>
          <?php foreach ($details['specifications'] as $i => $spec): ?><tr><th scope="row"<?= $editable('spec_name', $spec['name'], $i, 'spec.set') ?>><?= $escape($spec['name']) ?></th><td<?= $editable('spec_value', $spec['value'], $i, 'spec.set') ?>><?= $escape($spec['value']) ?></td><?php if ($editing): ?><td><button type="button" class="inline-tiny" data-editor-action="spec-remove" data-editor-index="<?= $i ?>" aria-label="Odebrat parametr">×</button></td><?php endif; ?></tr><?php endforeach; ?>
        </tbody></table></div><?php if ($editing): ?><button class="inline-small" type="button" data-editor-action="spec-add">＋ Přidat parametr</button><?php endif; ?></aside><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($editing && $productHistory !== []): ?><details class="inline-history"><summary>Historie úprav (<?= count($productHistory) ?>)</summary><p>Načtení starší verze vytvoří další novou revizi.</p>
      <?php foreach ($productHistory as $revision): ?><div class="inline-revision"><span>Revize <?= (int) $revision['revision_number'] ?> · <?= $escape($revision['saved_at']) ?> · <?= $escape($revision['name']) ?></span>
        <?php if ($revision['active_product_key'] === null): ?><button class="inline-small" type="button" data-editor-action="restore" data-editor-value="<?= (int) $revision['revision_number'] ?>">Obnovit jako novou revizi</button><?php else: ?><small>Aktuální</small><?php endif; ?>
      </div><?php endforeach; ?></details><?php endif; ?>
    <p><a href="<?= $escape($siteRoot . $language . ($categoryTrail !== [] ? '/kategorie-produktu/' . $categoryPath : '')) ?>#produkty">← Zpět na produkty</a></p>
    <?php if ($editing): ?><script type="application/json" id="inline-editor-config"><?= json_encode([
        'endpoint' => $basePath . 'admin.php', 'key' => $product['product_key'],
        'type' => 'product', 'language' => $language, 'revision' => (int) $product['revision_number'],
        'csrf' => $editToken,
      ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
      <?php require __DIR__ . '/media/picker.php'; ?><?php endif; ?>
  </main>
