<?php
// $mediaTargets, $selectedMedia and $mediaFiles come from src/Admin/media.php.
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$groups = [];
foreach ($mediaTargets as $target) $groups[$target['group']][] = $target;
?>
<section class="panel-panel">
  <p class="panel-eyebrow">Knihovna fotografií</p><h1>Fotografie</h1>
  <p>Vyber produkt, stránku nebo článek. Produkty jsou seřazené podle aktuální kategorie. Složka na disku používá stálý klíč obsahu, takže změna kategorie ani názvu neporuší odkazy a historii.</p>
  <?php if ($selectedMedia === null): ?>
    <p>Nejdřív založ produkt, stránku nebo článek.</p>
  <?php else: ?>
    <label for="media-target">Obsah</label>
    <select id="media-target" class="media-target-select">
      <?php foreach ($groups as $group => $targets): ?><optgroup label="<?= $escape($group) ?>">
        <?php foreach ($targets as $target): ?><?php $href = $adminUrl . '?' . http_build_query(['section' => 'media', 'type' => $target['type'], 'key' => $target['key'], 'language' => $target['language']]); ?>
          <option value="<?= $escape($href) ?>" <?= $target === $selectedMedia ? 'selected' : '' ?>><?= $escape($target['label']) ?></option>
        <?php endforeach; ?>
      </optgroup><?php endforeach; ?>
    </select>
    <p class="media-actions"><button type="button" class="panel-button" data-media-open>＋ Nahrát fotografie</button>
      <a href="<?= $escape($selectedMedia['editUrl']) ?>">Otevřít obsah k úpravě ↗</a></p>
    <p class="media-note">Hromadné nahrání vloží první snímek jako hlavní fotografii produktu a další do galerie. U stránek a článků přidá obrazové bloky. Původní fotografie zůstávají dostupné kvůli starším revizím.</p>
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
