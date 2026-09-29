<?php
// $selectedMedia and $mediaFiles come from src/Admin/media.php.
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="panel-panel">
  <p class="panel-eyebrow">Knihovna fotografií</p><h1>Fotografie</h1>
  <?php if ($mediaError !== ''): ?><p class="panel-error" role="alert"><?= $escape($mediaError) ?></p><?php endif; ?>
  <?php if ($selectedMedia === null): ?>
    <p>Fotografie spravuj u konkrétního produktu, stránky nebo článku. Tam uvidíš, která je hlavní a kde je použitá. Otevři obsah a stiskni <strong>Fotografie</strong>.</p>
    <p class="media-actions"><a href="<?= $escape($basePath . $site['default_language'] . '#produkty') ?>">Otevřít produkty</a>
      <a href="<?= $escape($basePath . $site['default_language'] . '/blog') ?>">Otevřít blog</a>
      <a href="<?= $escape($basePath . $site['default_language'] . '?manage=1') ?>">Najít neveřejný produkt</a>
      <a href="<?= $escape($adminUrl) ?>">Najít neveřejný dokument</a></p>
  <?php else: ?>
    <p><strong><?= $escape($selectedMedia['label']) ?></strong> · <?= $escape($selectedMedia['language']) ?>
      <?= $selectedMedia['category'] !== '' ? ' · Kategorie: ' . $escape($selectedMedia['category']) : '' ?></p>
    <?php if ($selectedMedia['type'] === 'product'): ?>
      <?php $mainPath = $selectedMedia['mainImagePath']; $mainUrl = str_starts_with($mainPath, 'images/') ? $basePath . $mainPath : $mainPath; ?>
      <p>Hlavní fotografie v aktuální revizi: <a data-media-main href="<?= $escape($mainUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($mainPath) ?></a></p>
    <?php endif; ?>
    <p class="media-actions"><button type="button" class="panel-button" data-media-open>＋ Nahrát fotografie</button>
      <a href="<?= $escape($selectedMedia['editUrl']) ?>">Otevřít obsah k úpravě ↗</a></p>
    <p class="media-note"><?= $selectedMedia['type'] === 'product'
        ? 'Z této stránky se první nahraný snímek stane hlavní fotografií produktu, další přibudou do galerie. Při nahrávání přímo na produktu se umístění řídí zvoleným tlačítkem.'
        : 'Nahrání vloží snímky jako obrazové bloky do dokumentu.' ?></p>
    <p class="media-note">Soubory tohoto obsahu jsou v <code><?= $escape($selectedMedia['directory']) ?>/</code>. Složka používá trvalý klíč, proto změna názvu a kategorie neporuší obrázky. Hlavní fotografie produktu je uložená v aktuální revizi produktu; galerie a bloky popisu jsou v jeho detailech. Nepoužité soubory zůstávají dostupné kvůli starším revizím a zkopírovaným odkazům.</p>
    <p class="media-note">Mřížka zobrazuje soubory nahrané do této složky. Starší obrázky a ručně vložené HTTPS odkazy mohou být v obsahu použité, ale v mřížce se nevypisují.</p>
    <p data-media-status role="status" aria-live="polite"></p>
    <div class="media-grid" id="media-page-grid" aria-label="Fotografie vybraného obsahu"></div>
    <script type="application/json" id="media-page-config"><?= json_encode([
        'endpoint' => $adminUrl, 'basePath' => $basePath, 'csrf' => $csrf,
        'type' => $selectedMedia['type'], 'key' => $selectedMedia['key'],
        'language' => $selectedMedia['language'], 'revision' => $selectedMedia['revision'],
        'files' => $mediaFiles,
      ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    <?php require __DIR__ . '/../media/picker.php'; ?>
  <?php endif; ?>
</section>
