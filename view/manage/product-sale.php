<?php if ($editing):
$purchasePartners = $purchasePartners ?? [];
$saleValues = array_replace($productSale ?? [], $productSaleForm ?? []);
if (isset($productSaleForm['variant_price'])) $saleValues['variant_prices'] = $productSaleForm['variant_price'];
$partnerFields = [];
foreach (array_keys(\SimpleStore\Product\PurchasePartnerRepository::FIELDS) as $field) {
    $partnerFields[$field] = $saleValues['partner_' . $field] ?? '';
}
$partnerFields['confirmed'] = $saleValues['partner_confirmed'] ?? '';
$hasNewPartner = implode('', $partnerFields) !== '';
$selectedPartner = null;
foreach ($purchasePartners as $partner) {
    if ((int) ($saleValues['partner_id'] ?? 0) === (int) $partner['id']) $selectedPartner = $partner;
}
$saleMissing = [];
if (($saleValues['mode'] ?? 'own') === 'assisted') {
    if (empty($saleValues['supplier_price_czk'])) $saleMissing[] = 'cenu dodavatele';
    if (trim((string) ($saleValues['delivery_note'] ?? '')) === '') $saleMissing[] = 'dobu dodání';
    if ($selectedPartner === null) $saleMissing[] = 'prodávajícího';
    elseif (\SimpleStore\Product\PurchasePartnerRepository::missing($selectedPartner) !== []) $saleMissing[] = 'úplné a ověřené údaje prodávajícího';
}
?>
<section class="supplier-links" id="product-sale" aria-label="Způsob prodeje">
  <h2>Způsob prodeje <small>Jen pro správce</small></h2>
  <?php if (!($productSalesReady ?? false)): ?>
    <p><a href="<?= $escape($siteRoot . 'admin.php?section=database') ?>">Nejprve aktualizuj databázi.</a></p>
  <?php else: ?>
    <?php if (($_GET['sale_saved'] ?? '') === '1'): ?><p role="status">Nastavení prodeje bylo uloženo.</p><?php endif; ?>
    <?php if (($productSaleError ?? '') !== ''): ?><p role="alert"><?= $escape($productSaleError) ?> Zadané údaje zůstaly ve formuláři.</p>
      <p><a target="_blank" rel="noopener" href="<?= $escape($siteRoot . $language . '/produkt/' . rawurlencode($product['slug']) . '?edit=1#product-sale') ?>">Otevřít uložené nastavení v nové kartě</a></p><?php endif; ?>
    <?php if ($saleMissing !== []): ?><p>Rozpracované nastavení – doplň <?= $escape(implode(', ', $saleMissing)) ?>. Zákazník může objednat až úplnou nabídku.</p><?php endif; ?>
    <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>" class="supplier-form" novalidate>
      <input type="hidden" name="action" value="product-sale"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
      <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
      <input type="hidden" name="version" value="<?= (int) ($saleValues['version'] ?? 0) ?>">
      <fieldset><legend>Kdo prodává zboží</legend>
        <label><input type="radio" name="mode" value="own" <?= ($saleValues['mode'] ?? 'own') === 'own' ? 'checked' : '' ?>> Prodáváme my</label>
        <label><input type="radio" name="mode" value="assisted" <?= ($saleValues['mode'] ?? '') === 'assisted' ? 'checked' : '' ?>> Asistovaný nákup u partnera</label>
      </fieldset>
      <p>Konečná cena produktu se nezvyšuje. Cena dodavatele se odečte a rozdíl je odměna za službu. Níže uvedené údaje vyplň pouze pro asistovaný nákup.</p>
      <label>Cena dodavatele za kus (celé Kč) <input type="number" name="supplier_price_czk" min="1" max="9999999" step="1" value="<?= $escape($saleValues['supplier_price_czk'] ?? '') ?>"></label>
      <?php if ($product['variant_inventory']['enabled'] ?? false): ?><details><summary>Odlišné nákupní ceny variant</summary>
        <p>Prázdné pole použije nákupní cenu produktu.</p>
        <?php foreach ($product['variant_inventory']['items'] as $saleVariant): ?>
          <label><?= $escape(implode(' / ', $saleVariant['choices'])) ?> <input type="number" min="1" max="9999999" step="1" name="variant_price[<?= $escape($saleVariant['id']) ?>]" value="<?= $escape($saleValues['variant_prices'][$saleVariant['id']] ?? '') ?>"></label>
        <?php endforeach; ?></details><?php endif; ?>
      <label>Předpokládaná doba dodání zákazníkovi <input name="delivery_note" maxlength="190" value="<?= $escape($saleValues['delivery_note'] ?? '') ?>" placeholder="Např. do 5 pracovních dnů od zaplacení"></label>
      <label>Vybraný prodávající <select name="partner_id"><option value="0">Zatím nevybrán / nový prodávající níže</option>
        <?php foreach (($purchasePartners ?? []) as $partner): ?><option value="<?= (int) $partner['id'] ?>" <?= (int) ($saleValues['partner_id'] ?? 0) === (int) $partner['id'] ? 'selected' : '' ?>><?= $escape(\SimpleStore\Product\PurchasePartnerRepository::label($partner) . ($partner['ico'] !== '' ? ' · IČO ' . $partner['ico'] : '') . (\SimpleStore\Product\PurchasePartnerRepository::missing($partner) !== [] ? ' · rozpracovaný' : '')) ?></option><?php endforeach; ?>
      </select></label>
      <p><a href="<?= $escape($siteRoot . 'admin.php?section=purchase-partners') ?>" target="_blank" rel="noopener">Seznam a úpravy prodávajících ↗</a>
        <?php if ($selectedPartner !== null): ?> · <a href="<?= $escape($siteRoot . 'admin.php?section=purchase-partners&id=' . (int) $selectedPartner['id'] . '#partner-form') ?>" target="_blank" rel="noopener">Upravit: <?= $escape(\SimpleStore\Product\PurchasePartnerRepository::label($selectedPartner)) ?> ↗</a><?php endif; ?></p>
      <details <?= $hasNewPartner ? 'open' : '' ?>><summary>Nový prodávající</summary>
        <p>Vyplň alespoň jeden údaj. Tlačítko níže uloží nový profil i nastavení prodeje a vybere nového prodávajícího pro tento produkt. Neúplné údaje můžeš doplnit později.</p>
        <?php $partnerPrefix = 'partner_'; require __DIR__ . '/purchase-partner-fields.php'; ?>
        <button type="submit" name="create_partner" value="1" class="inline-small">Uložit nového prodávajícího a nastavení</button>
      </details>
      <p>U asistovaného nákupu uvidí zákazník rozpis ceny, prodávajícího a dobu dodání v souhrnu objednávky. Soukromé zdrojové odkazy níže se nezveřejňují. Dostupnost a dopravu nastav podle skutečných možností partnera.</p>
      <button type="submit" class="inline-small">Uložit způsob prodeje</button>
      <p>Uloží se i rozpracované nastavení. Údaje partnera a nákupní ceny zůstanou zachované také při přepnutí na vlastní prodej.</p>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
