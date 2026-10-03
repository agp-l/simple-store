<?php
use SimpleStore\Media\MediaPath;

$homeUrl = $siteRoot . $language;
$editUrl = $homeUrl . '?homepage_edit=1#homepage-editor';
?>
<section class="homepage-editor" id="homepage-editor" aria-labelledby="homepage-editor-title">
  <div class="homepage-editor-heading"><div><p class="panel-eyebrow">Správa úvodní stránky</p>
    <h2 id="homepage-editor-title">Vybrané produkty</h2>
    <p>Vyber konkrétní zveřejněné zboží a seřaď ho. Změny se ukládají hned. Kategorie a hledání dál zobrazují celý katalog.</p>
  </div></div>
  <?php if (($_GET['saved'] ?? '') === '1'): ?><p class="homepage-editor-notice" role="status">Výběr na úvodní stránce byl uložen.</p><?php endif; ?>
  <?php if (is_string($_GET['homepage_error'] ?? null)): ?><p class="homepage-editor-error" role="alert"><?= $escape($_GET['homepage_error']) ?></p><?php endif; ?>
  <?php if (!$homepageReady): ?>
    <p class="homepage-editor-notice" role="alert">Nejdřív <a href="<?= $escape($siteRoot . 'admin.php?section=database') ?>">aktualizuj SQL tabulky</a>. Dosavadní výpis produktů zatím funguje.</p>
  <?php else: ?>
    <?php if (!$homepageConfigured): ?><p class="homepage-editor-notice">Zatím se zobrazuje automatický výpis katalogu. Přidej první produkt a úvodní stránka začne používat tvůj výběr.</p>
    <?php elseif ($homepageSelected === []): ?><p class="homepage-editor-notice">Výběr je prázdný. Přidej produkt níže, nebo vrať automatický výpis.</p><?php endif; ?>
    <?php if ($homepageSelected !== []): ?><ol class="homepage-picks">
      <?php foreach ($homepageSelected as $i => $selected): ?>
        <?php $isPublic = !empty($selected['published']); ?>
        <li><div class="homepage-pick-info"><strong><?= $escape($selected['name']) ?></strong>
          <small><?= $isPublic ? 'Zobrazuje se zákazníkům' : 'Skrytý nebo odstraněný – zákazníkům se neukazuje' ?></small></div>
          <?php foreach (['up' => '↑', 'down' => '↓', 'remove' => 'Odebrat'] as $operation => $label): ?>
            <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
              <input type="hidden" name="csrf" value="<?= $escape($adminCsrf) ?>">
              <input type="hidden" name="action" value="homepage-product">
              <input type="hidden" name="language" value="<?= $escape($language) ?>">
              <input type="hidden" name="operation" value="<?= $operation ?>">
              <input type="hidden" name="key" value="<?= $escape($selected['product_key']) ?>">
              <input type="hidden" name="pick" value="<?= $escape($homepagePick) ?>">
              <button type="submit" <?= ($operation === 'up' && $i === 0) || ($operation === 'down' && $i === count($homepageSelected) - 1) ? 'disabled' : '' ?> aria-label="<?= $escape($operation === 'up' ? 'Posunout ' . $selected['name'] . ' nahoru' : ($operation === 'down' ? 'Posunout ' . $selected['name'] . ' dolů' : 'Odebrat ' . $selected['name'])) ?>"><?= $label ?></button>
            </form>
          <?php endforeach; ?>
        </li>
      <?php endforeach; ?>
    </ol><?php endif; ?>
    <?php if ($homepageConfigured): ?><form class="homepage-reset" method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
      <input type="hidden" name="csrf" value="<?= $escape($adminCsrf) ?>"><input type="hidden" name="action" value="homepage-product">
      <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="operation" value="reset">
      <button type="submit">Vrátit automatický výpis katalogu</button>
    </form><?php endif; ?>
    <h3>Přidat z katalogu</h3>
    <form class="homepage-pick-search" method="get" action="<?= $escape($homeUrl) ?>">
      <input type="hidden" name="homepage_edit" value="1">
      <label>Najít produkt podle názvu nebo značky <input type="search" name="pick" value="<?= $escape($homepagePick) ?>" maxlength="200" placeholder="Např. batoh"></label>
      <button type="submit">Hledat</button>
    </form>
    <?php if ($homepageCandidates === []): ?><p>Žádné zveřejněné produkty neodpovídají hledání.</p><?php endif; ?>
    <ul class="homepage-candidates">
      <?php foreach ($homepageCandidates as $candidate): ?>
        <?php $imagePath = MediaPath::variant($candidate['image_path'], 'thumb'); ?>
        <li><img src="<?= $escape(str_starts_with($imagePath, 'images/') ? $siteRoot . $imagePath : $imagePath) ?>" alt="" loading="lazy">
          <div><strong><?= $escape($candidate['name']) ?></strong><small><?= $escape($candidate['brand']) ?> · <?= number_format((int) $candidate['price_czk'], 0, ',', ' ') ?> Kč</small></div>
          <?php if (in_array($candidate['product_key'], $homepageKeys, true)): ?><span>Vybráno</span>
          <?php else: ?><form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
            <input type="hidden" name="csrf" value="<?= $escape($adminCsrf) ?>"><input type="hidden" name="action" value="homepage-product">
            <input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="operation" value="add">
            <input type="hidden" name="key" value="<?= $escape($candidate['product_key']) ?>"><input type="hidden" name="pick" value="<?= $escape($homepagePick) ?>">
            <button type="submit" <?= count($homepageKeys) >= \SimpleStore\Product\HomepageProductSelection::LIMIT ? 'disabled' : '' ?>>Přidat na úvodní stránku</button>
          </form><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($homepageCandidateNext !== ''): ?><a class="homepage-next" href="<?= $escape($homepageCandidateNext . '#homepage-editor') ?>">Další produkty →</a><?php endif; ?>
  <?php endif; ?>
</section>
