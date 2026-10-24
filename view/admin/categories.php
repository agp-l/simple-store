<?php
use SimpleStore\Category\{CategoryPath, CategoryRepository};
use SimpleStore\Navigation\PanelUrl;
use SimpleStore\Rendering\Icon;
$categoryUrl = $adminUrl . '?' . http_build_query(['section' => 'categories', 'language' => $language]);
$categoryOrderVersion ??= CategoryRepository::orderVersion($categoryRows);
$categoryGroups = [];
foreach ($categoryRows as $row) $categoryGroups[CategoryPath::parent($row['path'])][] = $row;
?>
<link rel="stylesheet" href="<?= $escape($basePath . 'assets/admin-structure.css?v=' . filemtime(__DIR__ . '/../../assets/admin-structure.css')) ?>">
<script defer src="<?= $escape($basePath . 'assets/admin-structure.js?v=' . filemtime(__DIR__ . '/../../assets/admin-structure.js')) ?>"></script>
<div class="structure-workspace">
<div class="panel-intro"><div><p class="panel-eyebrow">Katalog / uspořádání</p><h1>Kategorie a podkategorie</h1><p>Složky pro zboží a základ automatických menu. Názvy, viditelnost a pořadí nastavíš tady.</p></div>
  <form class="panel-language" method="get" action="<?= PanelUrl::escape($adminUrl) ?>"><input type="hidden" name="section" value="categories"><label>Jazyk<select name="language"><?php foreach ($site['languages'] as $code): ?><option value="<?= $escape($code) ?>" <?= $language === $code ? 'selected' : '' ?>><?= $escape(strtoupper($code)) ?></option><?php endforeach; ?></select></label><button class="panel-action" type="submit">Zobrazit</button></form>
</div>
<?php if (!$categoryReady): ?><p class="panel-error">Nejdřív <a href="<?= PanelUrl::escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
<?php if ($categoryError !== ''): ?><p class="panel-error" role="alert"><?= $escape($categoryError) ?></p><?php endif; ?>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Kategorie byla uložena.</p><?php endif; ?>
<?php if (($_GET['ordered'] ?? '') === '1'): ?><p class="panel-notice" role="status">Pořadí kategorií bylo uloženo. Zařazení produktů zůstalo stejné.</p><?php endif; ?>
<div class="structure-guide"><div><?= Icon::render('folder') ?><strong>Kategorie třídí produkty</strong><p>Podkategorie patří pod svou hlavní kategorii. Počet zahrnuje i zboží v podkategoriích.</p></div><div><?= Icon::render('list') ?><strong>Menu z nich tvoří odkazy</strong><p>Menu se zdrojem „Kategorie“ přebírá zdejší názvy i pořadí. <a href="<?= PanelUrl::escape($adminUrl . '?section=menus&language=' . $language) ?>">Nastavit menu →</a></p></div><div><?= Icon::render('eye') ?><strong>Skrytí nemaže zboží</strong><p>Vypnutá větev zmizí z navigace. Prázdné podkategorie se skrývají automaticky.</p></div></div>
<section class="panel-panel structure-catalog" aria-labelledby="category-tree-title">
  <div class="structure-section-head"><div><p class="panel-eyebrow">Celý katalog</p><h2 id="category-tree-title">Strom kategorií <span class="structure-count"><?= count($categoryRows) ?></span></h2></div><a class="panel-action" href="<?= PanelUrl::escape($categoryUrl . '#category-create') ?>"><?= Icon::render('plus') ?> Přidat kategorii</a></div>
  <p class="structure-note">Přetáhni úchyt vedle názvu nebo použij šipky. Přesouvá se celá větev mezi kategoriemi stejného rodiče. Čísla lze upravit přímo — nižší číslo je výše, při shodě rozhoduje název. Změny potvrď tlačítkem <strong>Uložit pořadí</strong>.</p>
  <div class="structure-tree-toolbar" data-tree-tools hidden><label><?= Icon::render('search') ?><span class="sr-only">Hledat kategorii</span><input type="search" data-tree-search placeholder="Najít kategorii podle názvu nebo adresy"></label><button type="button" class="panel-text-link" data-tree-expand>Rozbalit vše</button><button type="button" class="panel-text-link" data-tree-collapse>Sbalit podkategorie</button></div>
  <?php if ($categoryRows === []): ?><p class="panel-empty">Zatím tu není žádná kategorie. Vytvoř první níže.</p><?php else: ?>
  <form class="panel-form" method="post" action="<?= PanelUrl::escape($categoryUrl) ?>" data-category-order>
    <input type="hidden" name="action" value="category-order"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="order_version" value="<?= $escape($categoryOrderVersion) ?>">
    <div class="structure-tree-labels"><span>Kategorie / zboží na webu</span><span>Viditelnost</span><span>Pořadí</span><span>Úpravy</span></div>
    <?php $renderCategoryGroup = static function (string $parent) use (&$renderCategoryGroup, $categoryGroups, $categories, $language, $categoryUrl, $selectedCategory, $escape): void { ?>
      <ol class="structure-tree" data-category-group="<?= $escape($parent) ?>">
      <?php foreach ($categoryGroups[$parent] ?? [] as $row): $hasChildren = isset($categoryGroups[$row['path']]); $visibility = $categories->visibility($language, $row['path']); ?>
        <li data-category-node="<?= $escape($row['path']) ?>" data-category-name="<?= $escape($row['title']) ?>">
          <div class="structure-category-row<?= ($selectedCategory['path'] ?? '') === $row['path'] ? ' is-selected' : '' ?>" data-category-row>
            <div class="structure-category-identity">
              <button type="button" class="structure-drag" data-tree-drag aria-label="Přetáhnout <?= $escape($row['title']) ?>" title="Změnit pořadí mezi sourozenci" hidden>⠿</button>
              <?php if ($hasChildren): ?><button type="button" class="structure-toggle" data-tree-toggle aria-expanded="true" aria-label="Podkategorie: <?= $escape($row['title']) ?>" hidden>⌄</button><?php else: ?><span class="structure-leaf"><?= Icon::render('folder') ?></span><?php endif; ?>
              <div><a class="structure-category-name" href="<?= PanelUrl::escape($categoryUrl . '&edit=' . rawurlencode($row['path']) . '#category-edit') ?>"><?= $escape($row['title']) ?></a><small><?= $escape($row['path']) ?> <span>· <?= $categories->productCount($language, $row['path']) ?> produktů</span></small></div>
            </div>
            <span class="structure-badge" data-visibility="<?= $escape($visibility) ?>"><?= Icon::render($visibility === 'visible' ? 'eye' : 'eye-off') ?><?= ['visible' => 'Na webu', 'empty' => 'Prázdná', 'hidden' => 'Skrytá'][$visibility] ?></span>
            <label class="structure-order-number"><span class="sr-only">Pořadí: <?= $escape($row['title']) ?></span><input type="number" min="0" max="65535" required name="positions[<?= $escape($row['path']) ?>]" value="<?= (int) $row['sort_order'] ?>" data-tree-order></label>
            <div class="structure-row-actions"><span class="structure-arrows" hidden><button type="button" data-tree-up aria-label="Posunout nahoru: <?= $escape($row['title']) ?>">↑</button><button type="button" data-tree-down aria-label="Posunout dolů: <?= $escape($row['title']) ?>">↓</button></span><a href="<?= PanelUrl::escape($categoryUrl . '&edit=' . rawurlencode($row['path']) . '#category-edit') ?>" aria-label="Upravit <?= $escape($row['title']) ?>"><?= Icon::render('edit') ?></a><a href="<?= PanelUrl::escape($categoryUrl . '&parent=' . rawurlencode($row['path']) . '#category-create') ?>" aria-label="Přidat podkategorii do <?= $escape($row['title']) ?>"><?= Icon::render('plus') ?></a></div>
          </div>
          <?php if ($hasChildren) $renderCategoryGroup($row['path']); ?>
        </li>
      <?php endforeach; ?>
      </ol>
    <?php }; $renderCategoryGroup(''); ?>
    <div class="structure-order-save"><p role="status" aria-live="polite" data-tree-status>Adresy kategorií a přiřazení produktů se řazením nemění.</p><button class="panel-action panel-button" type="submit"><?= Icon::render('save') ?> Uložit pořadí</button></div>
  </form>
  <?php endif; ?>
</section>
<div class="structure-editors">
  <?php if ($selectedCategory !== null): ?>
  <section class="panel-panel" id="category-edit"><p class="panel-eyebrow">Upravit kategorii</p><h2><?= $escape($selectedCategory['title']) ?></h2><p class="structure-path"><?= $escape($selectedCategory['path']) ?></p>
    <form class="panel-form" method="post" action="<?= PanelUrl::escape($categoryUrl . '&edit=' . rawurlencode($selectedCategory['path']) . '#category-edit') ?>">
      <input type="hidden" name="action" value="category-update"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="path" value="<?= $escape($selectedCategory['path']) ?>">
      <label>Název pro zákazníka<input name="title" maxlength="160" required value="<?= $escape($selectedCategory['title']) ?>"></label>
      <label>Pořadí mezi sourozenci<input type="number" name="sort_order" min="0" max="65535" required value="<?= (int) $selectedCategory['sort_order'] ?>"></label>
      <label class="panel-check"><input type="checkbox" name="enabled" value="1" <?= $selectedCategory['enabled'] ? 'checked' : '' ?>> Zobrazovat větev v navigaci</label>
      <p class="panel-help">Skrytí se týká i podkategorií. Zboží zůstane v katalogu a v nadřazené kategorii. Prázdná podkategorie se objeví až po zveřejnění produktu; skrytý rodič musí být také zapnutý.</p>
      <div class="panel-form-actions"><button class="panel-action panel-button" type="submit"><?= Icon::render('save') ?> Uložit kategorii</button><a class="panel-text-link" href="<?= PanelUrl::escape($categoryUrl) ?>">Zavřít úpravu</a></div>
    </form>
  </section>
  <?php endif; ?>
  <section class="panel-panel" id="category-create"><p class="panel-eyebrow">Rozšířit katalog</p><h2><?= $newParent === '' ? 'Přidat kategorii' : 'Přidat podkategorii' ?></h2>
    <form class="panel-form" method="post" action="<?= PanelUrl::escape($categoryUrl . '&parent=' . rawurlencode($newParent) . '#category-create') ?>">
      <input type="hidden" name="action" value="category-create"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
      <label>Kam kategorii zařadit<select name="parent"><option value="">Hlavní kategorie</option><?php foreach ($categoryRows as $row): ?><option value="<?= $escape($row['path']) ?>" <?= $newParent === $row['path'] ? 'selected' : '' ?>><?= $escape(str_repeat('— ', (int) $row['depth']) . $row['title']) ?></option><?php endforeach; ?></select></label>
      <label>Název pro zákazníka<input name="title" maxlength="160" required placeholder="Například Zimní spacáky"></label>
      <div class="structure-form-columns"><label>Adresa (volitelné)<input name="slug" placeholder="zimni-spacaky" pattern="[a-z0-9]+(-[a-z0-9]+)*"></label><label>Pořadí<input type="number" name="sort_order" min="0" max="65535" value="10" required></label></div>
      <p class="panel-help">Prázdnou adresu vytvoříme z názvu. Adresa je stálá, aby odkazy i přiřazené zboží zůstaly platné.</p>
      <button class="panel-action panel-button" type="submit" <?= $categoryReady ? '' : 'disabled' ?>><?= Icon::render('plus') ?> Vytvořit kategorii</button>
    </form>
  </section>
</div>
<?php $backupKind = 'navigation'; require __DIR__ . '/configuration-transfer.php'; ?>
</div>
