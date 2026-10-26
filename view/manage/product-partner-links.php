<?php
$partnerGroups = \SimpleStore\Product\PartnerLinkPresentation::partition($partnerLinks, time());
$partnerLabel = $partnerVariantKey !== '' ? \SimpleStore\Product\ProductVariants::label($variant['choices']) : 'Základní provedení';
$partnerPhoto = $partnerVariantKey !== '' ? \SimpleStore\Product\VariantImages::resolve($variant, $variantRows, $product['image_path']) : $product['image_path'];
$partnerOwnPhoto = $partnerVariantKey !== '' && $variant['image_path'] !== '';
?>
<div class="variant-partner-links" <?= $partnerVariantKey !== '' ? 'aria-labelledby="variant-label-'.$escape($partnerVariantKey).'"' : 'aria-label="Partnerské odkazy produktu"' ?>>
<?php if (empty($partnerSourceOnly)): ?>
  <div class="partner-context">
    <a class="partner-photo" data-partner-image-link href="<?= $escape($imageUrl($partnerPhoto)) ?>" target="_blank" rel="noopener noreferrer" aria-label="Zvětšit fotografii: <?= $escape($partnerLabel) ?>"><img data-partner-image src="<?= $escape($imageUrl(\SimpleStore\Media\MediaPath::variant($partnerPhoto, 'thumb'))) ?>" alt="<?= $escape($product['name'].' · '.$partnerLabel) ?>" width="80" height="80" loading="lazy"></a>
    <div><strong class="variant-partner-title"><?= \SimpleStore\Rendering\Icon::render('external') ?> Partnerské odkazy <span>· <?= count($partnerGroups['active']) ?> · Jen pro správce</span></strong>
      <span><?= $escape($partnerLabel) ?></span>
      <p class="partner-photo-note" data-partner-image-note><?= $partnerVariantKey === '' ? 'Fotografie produktu.' : ($partnerOwnPhoto ? 'Fotografie přiřazená této variantě.' : ($partnerPhoto !== $product['image_path'] ? 'Fotografie stejné barvy.' : 'Společná fotografie — barva zatím není přiřazená.')) ?></p>
    </div>
  </div>
  <p class="partner-save-scope">Každý partnerský odkaz se ukládá samostatně tlačítkem „Uložit pouze odkaz“.</p>
<?php endif; ?>
<?php if (($partnerVariantKey !== '' && ($_GET['supplier_variant'] ?? '') === $partnerVariantKey) || ($partnerVariantKey === '' && !isset($_GET['supplier_variant']))): ?>
  <?php if (($_GET['supplier_saved'] ?? '') === '1'): ?><p role="status">Partnerské odkazy byly aktualizovány.</p><?php endif; ?>
  <?php if (isset($_GET['supplier_error']) && is_string($_GET['supplier_error'])): ?><p class="supplier-error" role="alert"><?= $escape($_GET['supplier_error']) ?></p><?php endif; ?>
<?php endif; ?>
<?php foreach (array_merge($partnerGroups['active'], [null]) as $partnerLink) require __DIR__.'/product-partner-link-form.php'; ?>
<?php if ($partnerGroups['redundant'] !== []): ?>
  <details class="partner-older-links" <?= isset($_GET['supplier_error']) && ($_GET['supplier_variant'] ?? '') === $partnerVariantKey ? 'open' : '' ?>>
    <summary>Další převzaté odkazy (<?= count($partnerGroups['redundant']) ?>)</summary>
    <p>Stejnou nabídku už zastupuje přesný odkaz výše. Původní adresy jsou zachované pro dohledání.</p>
    <?php foreach ($partnerGroups['redundant'] as $partnerLink) require __DIR__.'/product-partner-link-form.php'; ?>
  </details>
<?php endif; ?>
</div>
