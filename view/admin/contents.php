      <div class="panel-intro">
        <div><p class="panel-eyebrow">Obsah webu</p><h1>Stránky a koncepty</h1>
          <p>Vyhledej obsah a otevři jeho skutečnou stránku k úpravě. Nový obsah zakládej přímo na webu.</p></div>
        <div class="panel-quick">
          <a href="<?= $escape($basePath . $site['default_language']) ?>">Na web ↗</a>
          <a href="<?= $escape($basePath . $site['default_language'] . '/blog') ?>">Blog ↗</a>
        </div>
      </div>
      <section class="panel-panel panel-list" aria-labelledby="panel-list-title">
        <h2 id="panel-list-title">Najít stránku nebo článek</h2>
        <form class="panel-form" method="get" action="<?= $escape($adminUrl) ?>">
          <input type="hidden" name="section" value="contents">
          <label>Hledat podle názvu nebo adresy
            <input name="q" type="search" maxlength="200" value="<?= $escape($filterSearch) ?>" placeholder="Název nebo část adresy">
          </label>
          <div class="panel-fields-two">
            <label>Typ
              <select name="type">
                <option value="" <?= $filterType === '' ? 'selected' : '' ?>>Stránky i články</option>
                <option value="page" <?= $filterType === 'page' ? 'selected' : '' ?>>Stránky</option>
                <option value="post" <?= $filterType === 'post' ? 'selected' : '' ?>>Články</option>
              </select>
            </label>
            <label>Stav
              <select name="status">
                <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Vše</option>
                <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Koncepty</option>
                <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>Publikováno</option>
              </select>
            </label>
          </div>
          <?php if (count($site['languages']) > 1): ?>
            <label>Jazyk
              <select name="language">
                <option value="" <?= $filterLanguage === '' ? 'selected' : '' ?>>Všechny jazyky</option>
                <?php foreach ($site['languages'] as $code): ?><option value="<?= $escape($code) ?>" <?= $filterLanguage === $code ? 'selected' : '' ?>><?= $escape($code) ?></option><?php endforeach; ?>
              </select>
            </label>
          <?php endif; ?>
          <div class="panel-form-actions"><button class="panel-button" type="submit">Zobrazit</button>
            <a href="<?= $escape($adminUrl . '?section=contents') ?>">Zrušit filtry</a></div>
        </form>
        <h2>Výsledky <span><?= count($documents) ?> na stránce</span></h2>
        <?php if ($documents === []): ?><p class="panel-empty">Žádný obsah neodpovídá filtrům.</p><?php endif; ?>
        <?php foreach ($documents as $document): ?>
          <?php $link = $basePath . $document['language'] . ($document['type'] === 'post' ? '/blog' : '') . '/' . rawurlencode($document['slug']) . '?edit=1'; ?>
          <a class="panel-document" href="<?= $escape($link) ?>">
            <strong><?= $escape($document['title']) ?> ↗</strong>
            <span><?= $document['type'] === 'post' ? 'Článek' : 'Stránka' ?> · <?= $escape($document['language']) ?> · <?= $escape($document['slug']) ?></span>
            <small><?= $document['published'] ? 'Publikováno' : 'Koncept' ?><?= $document['type'] === 'page' && $document['published'] && empty($document['visible_in_menu']) ? ' · mimo menu' : '' ?> · revize <?= (int) $document['revision_number'] ?></small>
          </a>
          <?php $missingLanguages = array_values(array_diff($site['languages'], $translations[$document['document_key']] ?? [])); ?>
          <?php if ($missingLanguages !== []): ?>
            <form class="panel-translate" method="post" action="<?= $escape($adminUrl) ?>">
              <input type="hidden" name="action" value="create-translation"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
              <input type="hidden" name="key" value="<?= $escape($document['document_key']) ?>"><input type="hidden" name="source_language" value="<?= $escape($document['language']) ?>">
              <label>Nový překlad <select name="language"><?php foreach ($missingLanguages as $code): ?><option value="<?= $escape($code) ?>"><?= $escape($code) ?></option><?php endforeach; ?></select></label>
              <button type="submit">Vytvořit</button>
            </form>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($previousUrl !== '' || $nextUrl !== ''): ?>
          <nav class="panel-quick" aria-label="Další obsah">
            <?php if ($previousUrl !== ''): ?><a href="<?= $escape($previousUrl) ?>">← Předchozí</a><?php endif; ?>
            <?php if ($nextUrl !== ''): ?><a href="<?= $escape($nextUrl) ?>">Další →</a><?php endif; ?>
          </nav>
        <?php endif; ?>
      </section>
