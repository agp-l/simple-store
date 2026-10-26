<?php
$partnerIsVariant = $partnerVariantKey !== '';
$partnerHost = $partnerLink !== null ? \SimpleStore\Product\ProductPartnerAssignment::host($partnerLink['url']) : null;
$partnerStatus = '';
if ($partnerLink !== null && $partnerIsVariant) {
    $partnerStatus = ['verified'=>'Ověřená shoda','conflict'=>'Potřebuje kontrolu','unverified'=>'Varianta neověřena'][$partnerLink['verification']] ?? 'Neověřeno';
    if ($partnerLink['verification'] === 'verified' && (int) ($partnerLink['result']['expires_at'] ?? 0) <= time()) $partnerStatus = 'Čeká na nové ověření';
}
?>
<div class="variant-partner-entry<?= $partnerLink === null ? ' variant-partner-entry--new' : '' ?>">
  <div class="variant-partner-heading">
  <?php if ($partnerLink === null): ?><strong>Přidat partnerský odkaz</strong>
  <?php else: ?>
    <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($partnerLink['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= $escape($partnerLink['url']) ?>"><?= $escape($partnerLink['label']) ?> · Otevřít nabídku ↗</a>
    <span><?= $escape($partnerStatus ?: ($partnerHost ?? 'Partnerský e-shop')) ?></span>
  <?php endif; ?>
  </div>
  <?php if (!empty($partnerLink['result']['message'])): ?><p class="panel-help"><?= $escape($partnerLink['result']['message']) ?>
    <?php if (!empty($partnerLink['checked_at'])): ?> Nabídka z <?= $escape((new DateTimeImmutable('@'.$partnerLink['checked_at']))->setTimezone(new DateTimeZone('Europe/Prague'))->format('j. n. Y H:i')) ?>.<?php endif; ?>
    <?php if (($partnerLink['result']['status'] ?? '') === 'manual_review' && !empty($partnerLink['result']['url'])): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($partnerLink['result']['url']) ?>" target="_blank" rel="noopener noreferrer">Otevřít navrženou variantu ↗</a><?php endif; ?>
  </p><?php endif; ?>
  <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot.'admin/') ?>" class="variant-partner-form">
    <input type="hidden" name="action" value="product-supplier-link"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
    <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
  <?php if ($partnerIsVariant): ?>
    <input type="hidden" name="variant_key" value="<?= $escape($partnerVariantKey) ?>"><input type="hidden" name="link_key" value="<?= $escape($partnerLink['link_key'] ?? '') ?>"><input type="hidden" name="version" value="<?= (int) ($partnerLink['version'] ?? 0) ?>">
    <label>Partnerský e-shop<select name="partner_key"><option value="">Zatím nepřiřazený</option><?php foreach ($purchasePartners as $partner): if (empty($partner['partner_key'])) continue; ?><option value="<?= $escape($partner['partner_key']) ?>" <?= ($partnerLink['partner_key'] ?? '') === $partner['partner_key'] ? 'selected' : '' ?>><?= $escape(\SimpleStore\Product\PurchasePartnerRepository::label($partner)) ?></option><?php endforeach; ?></select></label>
  <?php else: ?>
    <input type="hidden" name="id" value="<?= (int) ($partnerLink['id'] ?? 0) ?>">
    <input type="hidden" name="expected_link" value="<?= $partnerLink !== null ? $escape(\SimpleStore\Product\ProductSupplierLinkRepository::version($partnerLink)) : '' ?>">
    <div class="partner-shop-label"><span>Partnerský e-shop</span><strong><?= $escape($partnerHost ?? 'Podle vložené adresy') ?></strong></div>
  <?php endif; ?>
    <label>Popisek <input name="label" maxlength="120" required value="<?= $escape($partnerLink['label'] ?? '') ?>" placeholder="Např. Gramino"></label>
    <label class="variant-partner-url">Adresa nabídky u partnera <input type="url" name="url" maxlength="1000" required value="<?= $escape($partnerLink['url'] ?? '') ?>" placeholder="https://…"></label>
    <div class="variant-partner-actions"><button class="inline-small" name="operation" value="<?= $partnerIsVariant ? 'variant-save' : 'save' ?>"><?= $partnerLink === null ? 'Přidat odkaz' : 'Uložit pouze odkaz' ?></button>
      <?php if ($partnerLink !== null): ?><button class="inline-small" name="operation" value="<?= $partnerIsVariant ? 'variant-remove' : 'remove' ?>" formnovalidate>Odebrat</button><?php endif; ?>
      <button class="inline-small" type="reset" data-partner-reset hidden>Vrátit změny odkazu</button>
    </div>
  </form>
</div>
