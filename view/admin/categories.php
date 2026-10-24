      <div class="panel-intro">
        <div><p class="panel-eyebrow">Katalog / struktura</p><h1>Kategorie a podkategorie</h1>
          <p>Každá adresa určuje místo ve stromu. Název, pořadí a viditelnost můžeš měnit kdykoliv.</p></div>
        <form class="panel-language" method="get" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>">
          <input type="hidden" name="section" value="categories">
          <label>Jazyk <select name="language"><?php foreach ($site['languages'] as $code): ?><option value="<?= $escape($code) ?>" <?= $language === $code ? 'selected' : '' ?>><?= $escape(strtoupper($code)) ?></option><?php endforeach; ?></select></label>
          <button type="submit" class="panel-action"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit</button>
        </form>
      </div>
      <?php if (!$categoryReady): ?><p class="panel-error">Pro správu kategorií <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
      <?php if ($categoryError !== ''): ?><p class="panel-error" role="alert"><?= $escape($categoryError) ?></p><?php endif; ?>
      <?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Kategorie byla uložena.</p><?php endif; ?>
      <div class="panel-grid panel-grid-catalog">
        <section class="panel-panel panel-list" aria-labelledby="category-list-title">
          <div class="panel-panel-head"><h2 id="category-list-title">Strom kategorií <span><?= count($categoryRows) ?></span></h2>
            <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=categories&language=' . rawurlencode($language)) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Nová</a></div>
          <?php if ($categoryRows === []): ?><p class="panel-empty">Zatím tu není žádná kategorie v tomto jazyce.</p><?php endif; ?>
          <div class="panel-tree">
            <?php foreach ($categoryRows as $row): ?>
              <?php $editUrl = $adminUrl . '?' . http_build_query(['section' => 'categories', 'language' => $language, 'edit' => $row['path']]); ?>
              <a class="panel-tree-row<?= ($selectedCategory['path'] ?? '') === $row['path'] ? ' is-active' : '' ?>" href="<?= \SimpleStore\Navigation\PanelUrl::escape($editUrl) ?>" style="--indent:<?= min(5, (int) $row['depth']) * 17 ?>px">
                <span class="panel-tree-name"><?= $escape($row['title']) ?></span>
                <small><?php $visibility = $categories->visibility($language, $row['path']); ?><?= \SimpleStore\Rendering\Icon::render($visibility === 'visible' ? 'eye' : 'eye-off') ?> <?= $visibility === 'visible' ? 'Na webu' : ($visibility === 'empty' ? 'Prázdná – automaticky skryta' : 'Skryta v navigaci') ?> · <?= (int) $row['sort_order'] ?></small>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
        <div class="panel-workspace">
          <?php if ($selectedCategory !== null): ?>
            <section class="panel-panel" aria-labelledby="category-edit-title">
              <p class="panel-eyebrow">Upravit sekci</p><h2 id="category-edit-title"><?= $escape($selectedCategory['title']) ?></h2>
              <p class="panel-path"><?= $escape($selectedCategory['path']) ?></p>
              <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?' . http_build_query(['section' => 'categories', 'language' => $language, 'edit' => $selectedCategory['path']])) ?>">
                <input type="hidden" name="action" value="category-update"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="path" value="<?= $escape($selectedCategory['path']) ?>">
                <label>Název <input name="title" maxlength="160" required value="<?= $escape($selectedCategory['title']) ?>"></label>
                <label>Pořadí mezi sourozenci <input type="number" name="sort_order" min="0" max="65535" required value="<?= (int) $selectedCategory['sort_order'] ?>"></label>
                <label class="panel-check"><input type="checkbox" name="enabled" value="1" <?= $selectedCategory['enabled'] ? 'checked' : '' ?>> Zobrazovat na webu</label>
                <p class="panel-help">Adresa zůstává stálá kvůli produktům a dalším podkategoriím. Skrytí odstraní kategorii i její podkategorie z navigace, nikoli jejich zboží. Produkty lze dál přidávat a zákazník je najde v nadřazené kategorii nebo katalogu. Prázdné podkategorie se schovají automaticky; po zveřejnění produktu se znovu objeví, pokud je tato volba zapnutá.</p>
                <div class="panel-form-actions"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit kategorii</button>
                  <?php if ($categories->find($language, $selectedCategory['path']) !== null): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . $language . '/kategorie-produktu/' . $selectedCategory['path']) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Otevřít na webu</a><?php endif; ?></div>
              </form>
            </section>
          <?php endif; ?>
          <section class="panel-panel" aria-labelledby="category-create-title">
            <p class="panel-eyebrow">Nová větev</p><h2 id="category-create-title">Přidat kategorii</h2>
            <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=categories&language=' . rawurlencode($language) . '&parent=' . rawurlencode($newParent)) ?>">
              <input type="hidden" name="action" value="category-create"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
              <input type="hidden" name="language" value="<?= $escape($language) ?>">
              <label>Nadřazená kategorie <select name="parent"><option value="">— Hlavní sekce —</option>
                <?php foreach ($categoryRows as $row): ?>
                  <option value="<?= $escape($row['path']) ?>" <?= $newParent === $row['path'] ? 'selected' : '' ?>><?= $escape(str_repeat('— ', (int) $row['depth']) . $row['title']) ?></option>
                <?php endforeach; ?></select></label>
              <label>Název <input name="title" maxlength="160" required placeholder="Například Zimní spacáky"></label>
              <label>Adresa (volitelné) <input name="slug" placeholder="zimni-spacaky" pattern="[a-z0-9]+(-[a-z0-9]+)*"></label>
              <p class="panel-help">Prázdnou adresu vytvoříme automaticky z názvu. Později ji neměníme, aby odkazy a produkty zůstaly platné.</p>
              <label>Pořadí <input type="number" name="sort_order" min="0" max="65535" value="10" required></label>
              <button class="panel-action panel-button" type="submit" <?= $categoryReady ? '' : 'disabled' ?>><?= \SimpleStore\Rendering\Icon::render('plus') ?> Vytvořit kategorii</button>
            </form>
          </section>
        </div>
      </div>
