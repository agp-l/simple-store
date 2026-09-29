      <div class="panel-intro">
        <div><p class="panel-eyebrow">Obchod</p><h1>Produkty</h1>
          <p>Produkt upravuješ přímo na jeho stránce. Klikni do textu nebo použij malé nástroje u fotografií a bloků.</p></div>
        <div class="panel-quick"><a href="<?= $escape($adminUrl) ?>">Stránky a články</a></div>
      </div>
      <div class="panel-grid">
        <section class="panel-panel panel-list" aria-labelledby="product-list-title">
          <h2 id="product-list-title">Produkty <span><?= count($productRows) ?></span></h2>
          <?php if ($productRows === []): ?><p class="panel-empty">Zatím žádné produkty. Vytvoř první koncept vedle seznamu.</p><?php endif; ?>
          <?php foreach ($productRows as $row): ?>
            <?php $editLink = $basePath . $row['language'] . '/produkt/' . rawurlencode($row['slug']) . '?edit=1'; ?>
            <a class="panel-document" href="<?= $escape($editLink) ?>"><strong><?= $escape($row['name']) ?> ↗</strong>
              <span><?= $escape($row['slug']) ?> · <?= $escape($row['language']) ?> · <?= number_format((int) $row['price_czk'], 0, ',', ' ') ?> Kč</span>
              <small><?= $row['published'] ? 'Veřejný produkt' : 'Neveřejný koncept' ?> · revize <?= (int) $row['revision_number'] ?></small>
            </a>
          <?php endforeach; ?>
        </section>
        <section class="panel-panel" aria-labelledby="product-new-title">
          <div class="panel-section-heading"><p class="panel-eyebrow">Nový koncept</p><h2 id="product-new-title">Začít přímo na stránce</h2></div>
          <p>Vznikne neveřejný produkt s ukázkovým textem, seznamem, tabulkou, fotografií a parametry. Všechno můžeš upravit nebo smazat.</p>
          <?php if (!$productSchemaReady): ?>
            <p class="panel-error" role="alert">Nejdřív znovu importuj aktuální <code>database/schema.sql</code>.</p>
          <?php endif; ?>
          <form class="panel-start-product" method="post" action="<?= $escape($adminUrl) ?>">
            <input type="hidden" name="action" value="create-product">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <?php if (count($site['languages']) === 1): ?>
              <input type="hidden" name="language" value="<?= $escape($site['default_language']) ?>">
            <?php else: ?>
              <label>Jazyk <select name="language"><?php foreach ($site['languages'] as $code): ?><option value="<?= $escape($code) ?>"><?= $escape($code) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <button class="panel-button" type="submit" <?= $productSchemaReady ? '' : 'disabled' ?>>+ Vytvořit produkt</button>
          </form>
        </section>
      </div>
