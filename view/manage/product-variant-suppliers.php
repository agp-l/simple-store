<?php if (!empty($product['variant_inventory']['items'])): ?>
<section class="supplier-links variant-supplier-panel"><details id="variant-supplier-links" <?= isset($_GET['supplier_variant']) ? 'open' : '' ?>><summary>Partnerské odkazy jednotlivých variant</summary>
<?php if (isset($_GET['supplier_variant'])): ?>
<?php if (($_GET['supplier_saved'] ?? '') === '1'): ?><p role="status">Partnerské odkazy variant byly aktualizovány.</p><?php endif; ?>
<?php if (isset($_GET['supplier_error']) && is_string($_GET['supplier_error'])): ?><p class="supplier-error" role="alert"><?= $escape($_GET['supplier_error']) ?></p><?php endif; ?>
<?php endif; ?>
<p>Partneři jsou jiné e-shopy pro asistovaný nákup. Vybraný prodávající zůstává v nastavení prodeje produktu. Převzatý odkaz ani ruční vložení nepotvrzuje shodu konkrétní velikosti nebo barvy.</p>
<?php if (!$variantSupplierReady): ?>
<p>Pro ukládání odkazů variant <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/?section=database') ?>">aktualizuj databázi</a>.</p>
<?php else: ?>
<form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>">
<input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="action" value="product-supplier-link"><input type="hidden" name="operation" value="inherit">
<input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
<button class="inline-small" type="submit">Doplnit chybějící společné odkazy k variantám</button>
</form>
<?php foreach ($product['variant_inventory']['items'] as $partnerVariant):
    $variantLinks = array_values(array_filter($variantSupplierLinks, static fn(array $link): bool => $link['variant_key'] === $partnerVariant['id']));
?>
<details class="supplier-add" <?= ($_GET['supplier_variant'] ?? '') === $partnerVariant['id'] ? 'open' : '' ?>><summary><?= $escape(\SimpleStore\Product\ProductVariants::label($partnerVariant['choices'])) ?> · odkazy: <?= count($variantLinks) ?><?= !$partnerVariant['enabled'] ? ' · vyřazená varianta' : '' ?></summary>
<?php if ($variantMode && $partnerVariant['enabled']): ?><p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $language . '/produkt/' . rawurlencode($product['slug']) . '?variant=' . $partnerVariant['id']) ?>">Otevřít přímý odkaz této varianty ↗</a></p><?php endif; ?>
<?php foreach (array_merge($variantLinks, [null]) as $variantLink): ?>
<details class="supplier-add"><summary><?= $variantLink === null ? 'Přidat partnerský odkaz' : $escape($variantLink['label']) . ' · ' . $escape(['verified' => 'Ověřená shoda', 'conflict' => 'Potřebuje kontrolu', 'unverified' => $variantLink['origin'] === 'inherited' ? 'Převzatý – varianta neověřena' : 'Varianta neověřena'][$variantLink['verification']] ?? 'Neověřeno') ?></summary>
<?php if ($variantLink !== null): ?><p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($variantLink['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($variantLink['url']) ?> ↗</a></p><?php endif; ?>
<form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" class="supplier-form">
<input type="hidden" name="action" value="product-supplier-link"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
<input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
<input type="hidden" name="variant_key" value="<?= $escape($partnerVariant['id']) ?>"><input type="hidden" name="link_key" value="<?= $escape($variantLink['link_key'] ?? '') ?>"><input type="hidden" name="version" value="<?= (int) ($variantLink['version'] ?? 0) ?>">
<label>Partnerský e-shop<select name="partner_key"><option value="">Zatím nepřiřazený</option><?php foreach ($purchasePartners as $partner): if (empty($partner['partner_key'])) continue; ?><option value="<?= $escape($partner['partner_key']) ?>" <?= ($variantLink['partner_key'] ?? '') === $partner['partner_key'] ? 'selected' : '' ?>><?= $escape(\SimpleStore\Product\PurchasePartnerRepository::label($partner)) ?></option><?php endforeach; ?></select></label>
<label>Popisek <input name="label" maxlength="120" required value="<?= $escape($variantLink['label'] ?? '') ?>"></label>
<label>Přesná adresa varianty u partnera <input type="url" name="url" maxlength="1000" required value="<?= $escape($variantLink['url'] ?? '') ?>" placeholder="https://…?variant=…&amp;size=…"></label>
<button class="inline-small" name="operation" value="variant-save">Uložit odkaz varianty</button>
<?php if ($variantLink !== null): ?><button class="inline-small" name="operation" value="variant-remove" formnovalidate>Odebrat odkaz varianty</button><?php endif; ?>
</form></details>
<?php endforeach; ?>
</details>
<?php endforeach; ?>
<?php endif; ?>
</details></section>
<?php endif; ?>
