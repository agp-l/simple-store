<?php $variantLinks = $variantLinksById[$variant['id']] ?? []; ?>
<div class="variant-partner-links" aria-labelledby="variant-label-<?= $variant['id'] ?>">
  <strong class="variant-partner-title">Partnerské odkazy <span>· <?= count($variantLinks) ?></span></strong>
  <?php if (($_GET['supplier_variant'] ?? '') === $variant['id']): ?>
    <?php if (($_GET['supplier_saved'] ?? '') === '1'): ?><p role="status">Partnerské odkazy varianty byly aktualizovány.</p><?php endif; ?>
    <?php if (isset($_GET['supplier_error']) && is_string($_GET['supplier_error'])): ?><p class="supplier-error" role="alert"><?= $escape($_GET['supplier_error']) ?></p><?php endif; ?>
  <?php endif; ?>
<?php foreach (array_merge($variantLinks, [null]) as $variantLink): ?>
<div class="variant-partner-entry<?= $variantLink === null ? ' variant-partner-entry--new' : '' ?>">
  <div class="variant-partner-heading">
    <?php if ($variantLink === null): ?><strong>Přidat partnerský odkaz</strong>
    <?php else: ?>
    <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($variantLink['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($variantLink['label']) ?> ↗</a>
    <span><?= $escape($variantLink['verification'] === 'verified' && (int)($variantLink['result']['expires_at']??0) <= time() ? 'Shoda čeká na nové ověření' : (['verified' => 'Ověřená shoda', 'conflict' => 'Potřebuje kontrolu', 'unverified' => $variantLink['origin'] === 'inherited' ? 'Převzatý – varianta neověřena' : 'Varianta neověřena'][$variantLink['verification']] ?? 'Neověřeno')) ?></span>
    <?php endif; ?>
  </div>
  <?php if (!empty($variantLink['result']['message'])): ?><p class="panel-help"><?= $escape($variantLink['result']['message']) ?>
    <?php if (!empty($variantLink['checked_at'])): ?> Poslední ověřená nabídka: <?= $escape((new DateTimeImmutable('@'.$variantLink['checked_at']))->setTimezone(new DateTimeZone('Europe/Prague'))->format('j. n. Y H:i')) ?>.<?php endif; ?>
    <?php if (($variantLink['result']['status']??'') === 'manual_review' && !empty($variantLink['result']['url'])): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($variantLink['result']['url']) ?>" target="_blank" rel="noopener noreferrer">Otevřít navrženou variantu ↗</a><?php endif; ?>
  </p><?php endif; ?>
<form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" class="variant-partner-form">
<input type="hidden" name="action" value="product-supplier-link"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
<input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
<input type="hidden" name="variant_key" value="<?= $escape($variant['id']) ?>"><input type="hidden" name="link_key" value="<?= $escape($variantLink['link_key'] ?? '') ?>"><input type="hidden" name="version" value="<?= (int) ($variantLink['version'] ?? 0) ?>">
<label>Partnerský e-shop<select name="partner_key"><option value="">Zatím nepřiřazený</option><?php foreach ($purchasePartners as $partner): if (empty($partner['partner_key'])) continue; ?><option value="<?= $escape($partner['partner_key']) ?>" <?= ($variantLink['partner_key'] ?? '') === $partner['partner_key'] ? 'selected' : '' ?>><?= $escape(\SimpleStore\Product\PurchasePartnerRepository::label($partner)) ?></option><?php endforeach; ?></select></label>
<label>Popisek <input name="label" maxlength="120" required value="<?= $escape($variantLink['label'] ?? '') ?>"></label>
<label class="variant-partner-url">Přesná adresa varianty u partnera <input type="url" name="url" maxlength="1000" required value="<?= $escape($variantLink['url'] ?? '') ?>" placeholder="https://…?variant=…&amp;size=…"></label>
<div class="variant-partner-actions"><button class="inline-small" name="operation" value="variant-save"><?= $variantLink === null ? 'Přidat odkaz' : 'Uložit odkaz' ?></button>
<?php if ($variantLink !== null): ?><button class="inline-small" name="operation" value="variant-remove" formnovalidate>Odebrat</button><?php endif; ?></div>
</form>
</div>
<?php endforeach; ?>
</div>
