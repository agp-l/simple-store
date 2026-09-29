      <?php $slotNames = ['primary' => 'Hlavní menu', 'category_tabs' => 'Podkategorie', 'utility' => 'Horní odkazy', 'footer' => 'Patička']; ?>
      <div class="admin-intro">
        <div><p class="admin-eyebrow">Navigace / umístění</p><h1>Menu webu</h1>
          <p>Každé místo si může vzít kategorie, stránky nebo vlastní odkazy. Nastavení je oddělené podle jazyka.</p></div>
        <form class="admin-language" method="get" action="<?= $escape($adminUrl) ?>">
          <input type="hidden" name="section" value="menus"><input type="hidden" name="slot" value="<?= $escape($slot) ?>">
          <label>Jazyk <select name="language"><?php foreach ($site['languages'] as $code): ?><option value="<?= $escape($code) ?>" <?= $language === $code ? 'selected' : '' ?>><?= $escape(strtoupper($code)) ?></option><?php endforeach; ?></select></label>
          <button type="submit">Zobrazit</button>
        </form>
      </div>
      <?php if (!$menuReady): ?><p class="admin-error">Pro změny menu importuj aktuální <code>database/schema.sql</code>. Výchozí menu zatím fungují dál.</p><?php endif; ?>
      <?php if ($menuError !== ''): ?><p class="admin-error" role="alert"><?= $escape($menuError) ?></p><?php endif; ?>
      <?php if (($_GET['saved'] ?? '') === '1'): ?><p class="admin-notice" role="status">Změna menu byla uložena.</p><?php endif; ?>
      <div class="admin-grid admin-grid-catalog">
        <aside class="admin-panel admin-list" aria-labelledby="menu-slots-title">
          <h2 id="menu-slots-title">Místa na webu</h2>
          <div class="admin-menu-slots">
            <?php foreach ($menuSlots as $name => $settings): ?>
              <a class="admin-tree-row<?= $slot === $name ? ' is-active' : '' ?>" href="<?= $escape($adminUrl . '?' . http_build_query(['section' => 'menus', 'language' => $language, 'slot' => $name])) ?>">
                <span class="admin-tree-name"><?= $escape($slotNames[$name] ?? $name) ?></span>
                <small><?= ['categories' => 'Kategorie', 'content' => 'Stránky', 'manual' => 'Vlastní odkazy'][$settings['source']] ?? 'Menu' ?></small>
              </a>
            <?php endforeach; ?>
          </div>
          <form class="admin-form admin-new-slot" method="post" action="<?= $escape($adminUrl . '?section=menus') ?>">
            <input type="hidden" name="action" value="menu-slot"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <input type="hidden" name="language" value="<?= $escape($language) ?>">
            <input type="hidden" name="source" value="manual"><input type="hidden" name="parent_path" value="">
            <label>Nové místo menu <input name="slot" pattern="[a-z][a-z0-9_]{0,39}" placeholder="napr_sidebox" required></label>
            <button type="submit" <?= $menuReady ? '' : 'disabled' ?>>＋ Založit</button>
            <p class="admin-help">Nové místo lze vypisovat ve své PHP šabloně přes <code>MenuManager::links('název')</code>.</p>
          </form>
        </aside>
        <div class="admin-workspace">
          <section class="admin-panel" aria-labelledby="menu-settings-title">
            <p class="admin-eyebrow">Zdroj odkazů</p><h2 id="menu-settings-title"><?= $escape($slotNames[$slot] ?? $slot) ?></h2>
            <p class="admin-help">Současné výchozí zdroje jsou v <code>config/menus.php</code>. Toto nastavení je pro vybraný jazyk přepíše.</p>
            <form class="admin-form" method="post" action="<?= $escape($adminUrl . '?' . http_build_query(['section' => 'menus', 'language' => $language, 'slot' => $slot])) ?>">
              <input type="hidden" name="action" value="menu-slot"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
              <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="slot" value="<?= $escape($slot) ?>">
              <label>Odkazy načítat z <select name="source">
                <?php foreach (['categories' => 'Kategorií', 'content' => 'Publikovaných stránek', 'manual' => 'Vlastních odkazů'] as $value => $label): ?>
                  <option value="<?= $value ?>" <?= $activeSlot['source'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select></label>
              <label>Kořen kategorií <select name="parent_path"><option value="">Hlavní sekce</option>
                <?php if ($slot === 'category_tabs'): ?><option value="@context" <?= ($activeSlot['parent'] ?? '') === '@context' ? 'selected' : '' ?>>Podle otevřené kategorie</option><?php endif; ?>
                <?php foreach ($categoryOptions as $category): ?><?php if ($categories->find($language, $category['path']) !== null): ?>
                  <option value="<?= $escape($category['path']) ?>" <?= ($activeSlot['parent'] ?? '') === $category['path'] ? 'selected' : '' ?>><?= $escape(str_repeat('— ', (int) $category['depth']) . $category['title']) ?></option>
                <?php endif; ?><?php endforeach; ?>
              </select></label>
              <label class="admin-check"><input type="checkbox" name="include_blog" value="1" <?= ($activeSlot['include_blog'] ?? false) ? 'checked' : '' ?>> Přidat odkaz na blog mezi stránky</label>
              <p class="admin-help">Kořen platí jen pro zdroj Kategorie. Přepnutí na vlastní odkazy zachová jejich dřívější obsah, pokud se později vrátíš.</p>
              <button class="admin-button" type="submit" <?= $menuReady ? '' : 'disabled' ?>>Uložit zdroj menu</button>
            </form>
          </section>
          <?php if ($activeSlot['source'] === 'categories'): ?>
            <section class="admin-panel admin-hint"><h2>Pořadí a názvy kategorií</h2>
              <p>Odkazy se automaticky načítají z katalogu. Řazení, zobrazení a názvy upravíš ve správě kategorií.</p>
              <a class="admin-text-link" href="<?= $escape($adminUrl . '?section=categories&language=' . rawurlencode($language)) ?>">Spravovat kategorie →</a>
            </section>
          <?php elseif ($activeSlot['source'] === 'manual'): ?>
            <section class="admin-panel" aria-labelledby="manual-items-title">
              <div class="admin-panel-head"><h2 id="manual-items-title">Vlastní odkazy <span><?= count($menuItems) ?></span></h2>
                <a href="<?= $escape($adminUrl . '?' . http_build_query(['section' => 'menus', 'language' => $language, 'slot' => $slot])) ?>">＋ Nový odkaz</a></div>
              <?php if ($menuItems === []): ?><p class="admin-empty">Zatím prázdné menu. Přidej první odkaz níže.</p><?php endif; ?>
              <div class="admin-tree"><?php foreach ($menuItems as $item): ?>
                <a class="admin-tree-row<?= ($selectedItem['id'] ?? '') === $item['id'] ? ' is-active' : '' ?>" style="--indent:<?= min(5, (int) $item['depth']) * 17 ?>px" href="<?= $escape($adminUrl . '?' . http_build_query(['section' => 'menus', 'language' => $language, 'slot' => $slot, 'item' => $item['id']])) ?>">
                  <span class="admin-tree-name"><?= $escape($item['label']) ?></span>
                  <small><?= $escape($item['target']) ?> · <?= (int) $item['sort_order'] ?></small>
                </a>
              <?php endforeach; ?></div>
              <form class="admin-form admin-item-form" method="post" action="<?= $escape($adminUrl . '?' . http_build_query(['section' => 'menus', 'language' => $language, 'slot' => $slot, 'item' => $selectedItem['id'] ?? ''])) ?>">
                <input type="hidden" name="action" value="menu-item-save"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="slot" value="<?= $escape($slot) ?>">
                <input type="hidden" name="id" value="<?= $escape($selectedItem['id'] ?? '') ?>">
                <h3><?= $selectedItem === null ? 'Přidat odkaz' : 'Upravit odkaz' ?></h3>
                <label>Text odkazu <input name="label" maxlength="80" required value="<?= $escape($selectedItem['label'] ?? '') ?>"></label>
                <div class="admin-fields-two">
                  <label>Kam vede <select name="target_type"><option value="category" <?= ($selectedItem['target_type'] ?? '') === 'category' ? 'selected' : '' ?>>Kategorie</option><option value="path" <?= ($selectedItem['target_type'] ?? '') === 'path' ? 'selected' : '' ?>>Stránka nebo blog</option></select></label>
                  <label>Cesta <input name="target" list="menu-targets" value="<?= $escape($selectedItem['target'] ?? '') ?>" placeholder="spani nebo blog" required></label>
                </div>
                <datalist id="menu-targets"><?php foreach ($categoryOptions as $category): ?><option value="<?= $escape($category['path']) ?>"><?= $escape($category['title']) ?></option><?php endforeach; ?>
                  <option value="blog">Blog</option><?php foreach ($pageRows as $page): ?><option value="<?= $escape($page['slug']) ?>"><?= $escape($page['title']) ?></option><?php endforeach; ?></datalist>
                <label>Vnořit pod odkaz <select name="parent_id"><option value="">Na hlavní úrovni</option>
                  <?php foreach ($menuItems as $item): ?><option value="<?= $escape($item['id']) ?>" <?= ($selectedItem['parent_id'] ?? '') === $item['id'] ? 'selected' : '' ?>><?= $escape(str_repeat('— ', (int) $item['depth']) . $item['label']) ?></option><?php endforeach; ?>
                </select></label>
                <label>Pořadí <input type="number" name="sort_order" min="0" max="65535" value="<?= (int) ($selectedItem['sort_order'] ?? 10) ?>" required></label>
                <p class="admin-help">Pro kategorii napiš cestu, například <code>spani/spacaky</code>. Pro stránku její adresu, například <code>o-nas</code>; pro blog <code>blog</code>.</p>
                <button class="admin-button" type="submit" <?= $menuReady ? '' : 'disabled' ?>><?= $selectedItem === null ? '＋ Přidat odkaz' : 'Uložit odkaz' ?></button>
              </form>
              <?php if ($selectedItem !== null): ?><form class="admin-remove-form" method="post" action="<?= $escape($adminUrl . '?section=menus') ?>">
                <input type="hidden" name="action" value="menu-item-remove"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="slot" value="<?= $escape($slot) ?>">
                <input type="hidden" name="id" value="<?= $escape($selectedItem['id']) ?>">
                <button type="submit">Odebrat odkaz</button><span>Jeho pododkazy se přesunou o úroveň výš.</span>
              </form><?php endif; ?>
            </section>
          <?php endif; ?>
          <?php if ($slot === 'utility'): ?>
            <section class="admin-panel" aria-labelledby="menu-pages-title">
              <p class="admin-eyebrow">Stránky</p><h2 id="menu-pages-title">Pořadí horních odkazů</h2>
              <p class="admin-help">Tyto volby platí, když horní menu čte publikované stránky. Uložení vytvoří novou revizi stránky.</p>
              <?php if ($pageRows === []): ?><p class="admin-empty">Zatím nejsou vytvořené stránky.</p><?php endif; ?>
              <?php foreach ($pageRows as $page): ?>
                <form class="admin-page-order" method="post" action="<?= $escape($adminUrl . '?section=menus&slot=utility') ?>">
                  <input type="hidden" name="action" value="page-menu"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                  <input type="hidden" name="slot" value="utility"><input type="hidden" name="language" value="<?= $escape($language) ?>">
                  <input type="hidden" name="key" value="<?= $escape($page['document_key']) ?>"><input type="hidden" name="revision" value="<?= (int) $page['revision_number'] ?>">
                  <div><strong><?= $escape($page['title']) ?></strong><small><?= $page['published'] ? 'Publikováno' : 'Koncept' ?> · <?= $escape($page['slug']) ?></small></div>
                  <label class="admin-check"><input type="checkbox" name="visible_in_menu" value="1" <?= $page['visible_in_menu'] ? 'checked' : '' ?>> V menu</label>
                  <label>Pořadí <input type="number" name="menu_order" min="0" max="65535" value="<?= (int) $page['menu_order'] ?>" required></label>
                  <button type="submit">Uložit</button>
                </form>
              <?php endforeach; ?>
            </section>
          <?php endif; ?>
        </div>
      </div>
