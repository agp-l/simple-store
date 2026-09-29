      <?php $slotNames = ['primary' => 'Hlavní menu', 'category_tabs' => 'Podkategorie', 'utility' => 'Horní odkazy', 'footer' => 'Patička']; ?>
      <div class="panel-intro">
        <div><p class="panel-eyebrow">Navigace / umístění</p><h1>Menu webu</h1>
          <p>Vyber místo na webu, zvol odkazy a uprav jejich text, cíl i pořadí.</p></div>
        <form class="panel-language" method="get" action="<?= $escape($adminUrl) ?>">
          <input type="hidden" name="section" value="menus"><input type="hidden" name="slot" value="<?= $escape($slot) ?>">
          <label>Jazyk <select name="language"><?php foreach ($site['languages'] as $code): ?><option value="<?= $escape($code) ?>" <?= $language === $code ? 'selected' : '' ?>><?= $escape(strtoupper($code)) ?></option><?php endforeach; ?></select></label>
          <button type="submit">Zobrazit</button>
        </form>
      </div>
      <?php if (!$menuReady): ?><p class="panel-error">Pro změny menu importuj aktuální <code>database/schema.sql</code>. Výchozí menu zatím fungují dál.</p><?php endif; ?>
      <?php if ($menuError !== ''): ?><p class="panel-error" role="alert"><?= $escape($menuError) ?></p><?php endif; ?>
      <?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Změna menu byla uložena.</p><?php endif; ?>
      <div class="panel-grid panel-grid-catalog">
        <aside class="panel-panel panel-list" aria-labelledby="menu-slots-title">
          <h2 id="menu-slots-title">Kde se menu zobrazí</h2>
          <div class="panel-menu-slots">
            <?php foreach ($menuSlots as $name => $settings): ?>
              <a class="panel-tree-row<?= $slot === $name ? ' is-active' : '' ?>" href="<?= $escape($adminUrl . '?' . http_build_query(['section' => 'menus', 'language' => $language, 'slot' => $name])) ?>">
                <span class="panel-tree-name"><?= $escape($slotNames[$name] ?? $name) ?></span>
                <small><?= ['categories' => 'Kategorie', 'content' => 'Stránky', 'manual' => 'Vlastní odkazy'][$settings['source']] ?? 'Menu' ?></small>
              </a>
            <?php endforeach; ?>
          </div>
          <p class="panel-help">Patička má jedno menu. Odkazy na koncepty stránek se ukážou až po zveřejnění.</p>
        </aside>
        <div class="panel-workspace">
          <section class="panel-panel" aria-labelledby="menu-settings-title">
            <p class="panel-eyebrow">Nastavení</p><h2 id="menu-settings-title"><?= $escape($slotNames[$slot] ?? $slot) ?></h2>
            <p class="panel-help">Vyber, co se v tomto místě bude zobrazovat. U vlastních odkazů můžeš upravit každou položku níže.</p>
            <form class="panel-form" method="post" action="<?= $escape($adminUrl . '?' . http_build_query(['section' => 'menus', 'language' => $language, 'slot' => $slot])) ?>">
              <input type="hidden" name="action" value="menu-slot"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
              <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="slot" value="<?= $escape($slot) ?>">
              <?php if ($slot === 'footer'): ?><label>Nadpis menu v patičce <input name="title" maxlength="80" required value="<?= $escape($activeSlot['title'] ?? 'Informace') ?>"></label><?php endif; ?>
              <label>Co se zobrazí <select name="source">
                <?php foreach (['manual' => 'Vlastní odkazy: přesné texty a pořadí', 'categories' => 'Kategorie produktů', 'content' => 'Publikované stránky označené do horního menu'] as $value => $label): ?>
                  <option value="<?= $value ?>" <?= $activeSlot['source'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select></label>
              <label>Začít od kategorie (jen při volbě Kategorie) <select name="parent_path"><option value="">Hlavní kategorie</option>
                <?php if ($slot === 'category_tabs'): ?><option value="@context" <?= ($activeSlot['parent'] ?? '') === '@context' ? 'selected' : '' ?>>Podle otevřené kategorie</option><?php endif; ?>
                <?php foreach ($categoryOptions as $category): ?><?php if ($categories->find($language, $category['path']) !== null): ?>
                  <option value="<?= $escape($category['path']) ?>" <?= ($activeSlot['parent'] ?? '') === $category['path'] ? 'selected' : '' ?>><?= $escape(str_repeat('— ', (int) $category['depth']) . $category['title']) ?></option>
                <?php endif; ?><?php endforeach; ?>
              </select></label>
              <label class="panel-check"><input type="checkbox" name="include_blog" value="1" <?= ($activeSlot['include_blog'] ?? false) ? 'checked' : '' ?>> Přidat odkaz na blog mezi stránky</label>
              <p class="panel-help">Přepnutí zdroje zachová dříve uložené vlastní odkazy. Volba Blog platí pouze pro zdroj Publikované stránky.</p>
              <button class="panel-button" type="submit" <?= $menuReady ? '' : 'disabled' ?>>Uložit zdroj menu</button>
            </form>
          </section>
          <?php if ($activeSlot['source'] === 'categories'): ?>
            <section class="panel-panel panel-hint"><h2>Pořadí a názvy kategorií</h2>
              <p>Odkazy se automaticky načítají z katalogu. Řazení, zobrazení a názvy upravíš ve správě kategorií.</p>
              <a class="panel-text-link" href="<?= $escape($adminUrl . '?section=categories&language=' . rawurlencode($language)) ?>">Spravovat kategorie →</a>
            </section>
          <?php elseif ($activeSlot['source'] === 'manual'): ?>
            <section class="panel-panel" aria-labelledby="manual-items-title">
              <div class="panel-panel-head"><h2 id="manual-items-title">Vlastní odkazy <span><?= count($menuItems) ?></span></h2>
                <a href="<?= $escape($adminUrl . '?' . http_build_query(['section' => 'menus', 'language' => $language, 'slot' => $slot]) . '#menu-item-form') ?>">＋ Nový odkaz</a></div>
              <?php if ($menuItems === []): ?><p class="panel-empty">Zatím prázdné menu. Přidej první odkaz níže.</p><?php endif; ?>
              <div class="panel-tree"><?php foreach ($menuItems as $item): ?>
                <a class="panel-tree-row<?= ($selectedItem['id'] ?? '') === $item['id'] ? ' is-active' : '' ?>" style="--indent:<?= min(5, (int) $item['depth']) * 17 ?>px" href="<?= $escape($adminUrl . '?' . http_build_query(['section' => 'menus', 'language' => $language, 'slot' => $slot, 'item' => $item['id']])) ?>">
                  <span class="panel-tree-name"><?= $escape($item['label']) ?></span>
                  <small><?= $escape($item['target']) ?> · <?= (int) $item['sort_order'] ?></small>
                </a>
              <?php endforeach; ?></div>
              <form class="panel-form panel-item-form" id="menu-item-form" method="post" action="<?= $escape($adminUrl . '?' . http_build_query(['section' => 'menus', 'language' => $language, 'slot' => $slot, 'item' => $selectedItem['id'] ?? ''])) ?>">
                <input type="hidden" name="action" value="menu-item-save"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="slot" value="<?= $escape($slot) ?>">
                <input type="hidden" name="id" value="<?= $escape($selectedItem['id'] ?? '') ?>">
                <h3><?= $selectedItem === null ? 'Přidat odkaz' : 'Upravit odkaz' ?></h3>
                <label>Text odkazu <input name="label" maxlength="80" required value="<?= $escape($selectedItem['label'] ?? '') ?>"></label>
                <?php $selectedType = ($selectedItem['target_type'] ?? '') === 'category' ? 'category' :
                    (($selectedItem['target_type'] ?? '') === 'external' ? 'external' :
                    (($selectedItem['target'] ?? null) === '' ? 'home' :
                    (($selectedItem['target'] ?? '') === 'blog' ? 'blog' :
                    (in_array($selectedItem['target'] ?? '', array_column($pageRows, 'slug'), true) || $selectedItem === null ? 'page' : 'custom')))); ?>
                <label>Kam odkaz vede <select name="destination_type">
                  <?php foreach (['page' => 'Stránka webu', 'category' => 'Kategorie produktů', 'home' => 'Úvodní stránka',
                    'blog' => 'Blog', 'custom' => 'Jiná vnitřní cesta', 'external' => 'Externí web nebo e-mail'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $selectedType === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                  <?php endforeach; ?>
                </select></label>
                <p class="panel-help">Vyplň jen pole odpovídající vybranému typu cíle. Pro úvod a blog není potřeba nic dalšího.</p>
                <label>Stránka <select name="destination_page"><option value="">Vyber stránku</option>
                  <?php foreach ($pageRows as $page): ?><option value="<?= $escape($page['slug']) ?>" <?= $selectedType === 'page' && ($selectedItem['target'] ?? '') === $page['slug'] ? 'selected' : '' ?>><?= $escape($page['title']) ?><?= $page['published'] ? '' : ' (koncept)' ?></option><?php endforeach; ?>
                </select></label>
                <label>Kategorie <select name="destination_category"><option value="">Vyber kategorii</option>
                  <?php foreach ($categoryOptions as $category): ?><option value="<?= $escape($category['path']) ?>" <?= $selectedType === 'category' && ($selectedItem['target'] ?? '') === $category['path'] ? 'selected' : '' ?>><?= $escape(str_repeat('— ', (int) $category['depth']) . $category['title']) ?></option><?php endforeach; ?>
                </select></label>
                <label>Jiná vnitřní cesta <input name="destination_custom" value="<?= $selectedType === 'custom' ? $escape($selectedItem['target']) : '' ?>" placeholder="např. kosik"></label>
                <label>Externí odkaz nebo e-mail <input name="destination_external" value="<?= $selectedType === 'external' ? $escape($selectedItem['target']) : '' ?>" placeholder="https://priklad.cz nebo mailto:info@priklad.cz"></label>
                <label>Vnořit pod odkaz <select name="parent_id"><option value="">Na hlavní úrovni</option>
                  <?php foreach ($menuItems as $item): ?><option value="<?= $escape($item['id']) ?>" <?= ($selectedItem['parent_id'] ?? '') === $item['id'] ? 'selected' : '' ?>><?= $escape(str_repeat('— ', (int) $item['depth']) . $item['label']) ?></option><?php endforeach; ?>
                </select></label>
                <label>Pořadí (nižší číslo dříve) <input type="number" name="sort_order" min="0" max="65535" value="<?= (int) ($selectedItem['sort_order'] ?? (count($menuItems) * 10)) ?>" required></label>
                <button class="panel-button" type="submit" <?= $menuReady ? '' : 'disabled' ?>><?= $selectedItem === null ? '＋ Přidat odkaz' : 'Uložit odkaz' ?></button>
              </form>
              <?php if ($selectedItem !== null): ?><form class="panel-remove-form" method="post" action="<?= $escape($adminUrl . '?section=menus') ?>">
                <input type="hidden" name="action" value="menu-item-remove"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="slot" value="<?= $escape($slot) ?>">
                <input type="hidden" name="id" value="<?= $escape($selectedItem['id']) ?>">
                <button type="submit">Odebrat odkaz</button><span>Jeho pododkazy se přesunou o úroveň výš.</span>
              </form><?php endif; ?>
            </section>
          <?php endif; ?>
          <?php if ($slot === 'utility'): ?>
            <section class="panel-panel" aria-labelledby="menu-pages-title">
              <p class="panel-eyebrow">Stránky</p><h2 id="menu-pages-title">Pořadí horních odkazů</h2>
              <p class="panel-help">Tyto volby platí, když horní menu čte publikované stránky. Uložení vytvoří novou revizi stránky.</p>
              <?php if ($pageRows === []): ?><p class="panel-empty">Zatím nejsou vytvořené stránky.</p><?php endif; ?>
              <?php foreach ($pageRows as $page): ?>
                <form class="panel-page-order" method="post" action="<?= $escape($adminUrl . '?section=menus&slot=utility') ?>">
                  <input type="hidden" name="action" value="page-menu"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                  <input type="hidden" name="slot" value="utility"><input type="hidden" name="language" value="<?= $escape($language) ?>">
                  <input type="hidden" name="key" value="<?= $escape($page['document_key']) ?>"><input type="hidden" name="revision" value="<?= (int) $page['revision_number'] ?>">
                  <div><strong><?= $escape($page['title']) ?></strong><small><?= $page['published'] ? 'Publikováno' : 'Koncept' ?> · <?= $escape($page['slug']) ?></small></div>
                  <label class="panel-check"><input type="checkbox" name="visible_in_menu" value="1" <?= $page['visible_in_menu'] ? 'checked' : '' ?>> V menu</label>
                  <label>Pořadí <input type="number" name="menu_order" min="0" max="65535" value="<?= (int) $page['menu_order'] ?>" required></label>
                  <button type="submit">Uložit</button>
                </form>
              <?php endforeach; ?>
            </section>
          <?php endif; ?>
        </div>
      </div>
