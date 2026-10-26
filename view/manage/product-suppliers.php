<?php if ($canEdit): ?>
<div id="supplier-links" class="product-partner-base">
<?php if (!$supplierLinksReady): ?>
  <p>Pro partnerské odkazy nejprve <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot.'admin/?section=database') ?>">aktualizuj databázi</a>.</p>
<?php else:
  $partnerVariantKey = ''; $partnerLinks = $supplierLinks;
  require __DIR__.'/product-partner-links.php';
endif; ?>
</div>
<?php endif; ?>
