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
    if ($selectedPartner === null) $saleMissing[] = 'prodávajícího';
    elseif (\SimpleStore\Product\PurchasePartnerRepository::missing($selectedPartner) !== []) $saleMissing[] = 'úplné a ověřené údaje prodávajícího';
}
$saleVariants = ($product['variant_inventory']['enabled'] ?? false) ? $product['variant_inventory']['items'] : [];
$basePrice = (int) $product['price_czk'];
$fee = is_numeric($saleValues['supplier_price_czk'] ?? null) ? $basePrice - (int) $saleValues['supplier_price_czk'] : null;
$money = static fn (int $value): string => number_format($value, 0, ',', ' ') . ' Kč';
$partnerAdminUrl = $siteRoot . 'admin/?section=purchase-partners';
?>
<section class="product-sale" id="product-sale" aria-labelledby="product-sale-title">
  <div class="sale-heading"><h2 id="product-sale-title">Způsob prodeje</h2><span>Jen pro správce</span></div>
  <?php if (!($productSalesReady ?? false)): ?>
    <p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/?section=database') ?>">Nejprve aktualizuj databázi.</a></p>
  <?php else: ?>
    <?php if (($_GET['sale_saved'] ?? '') === '1' && ($productSaleError ?? '') === ''): ?><p class="sale-notice" role="status">Nastavení prodeje bylo uloženo.</p><?php endif; ?>
    <?php if (($productSaleError ?? '') !== ''): ?><div class="sale-notice sale-notice--error" role="alert"><?= $escape($productSaleError) ?> Zadané údaje zůstaly ve formuláři.
      <a target="_blank" rel="noopener" href="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . $language . '/produkt/' . rawurlencode($product['slug']) . '?edit=1#product-sale') ?>">Porovnat s uloženým nastavením ↗</a></div><?php endif; ?>
    <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($siteRoot . 'admin/') ?>" class="sale-form" data-product-sale-form data-base-price="<?= $basePrice ?>" data-recovered="<?= ($productSaleError ?? '') !== '' ? '1' : '0' ?>" novalidate>
      <input type="hidden" name="action" value="product-sale"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
      <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
      <input type="hidden" name="version" value="<?= (int) ($saleValues['version'] ?? 0) ?>">
      <fieldset class="sale-modes"><legend class="sale-sr-only">Kdo prodává zboží</legend>
        <label class="sale-mode"><input type="radio" name="mode" value="own" <?= ($saleValues['mode'] ?? 'own') === 'own' ? 'checked' : '' ?>><span><strong>Prodáváme my</strong><small>Vlastní prodej zboží</small></span></label>
        <label class="sale-mode"><input type="radio" name="mode" value="assisted" <?= ($saleValues['mode'] ?? '') === 'assisted' ? 'checked' : '' ?>><span><strong>Asistovaný nákup</strong><small>Nákup u vybraného partnera</small></span></label>
      </fieldset>
      <div class="sale-assistance" data-sale-assistance>
        <div class="sale-main-grid">
          <div class="sale-partner">
            <label>Vybraný prodávající <select name="partner_id"><option value="0" data-missing="Vyber prodávajícího nebo vyplň nového.">Vybrat prodávajícího…</option>
              <?php foreach ($purchasePartners as $partner): $missing = \SimpleStore\Product\PurchasePartnerRepository::missing($partner); ?>
                <option value="<?= (int) $partner['id'] ?>" data-missing="<?= $escape($missing === [] ? '' : 'Doplň: ' . implode(', ', $missing) . '.') ?>" <?= (int) ($saleValues['partner_id'] ?? 0) === (int) $partner['id'] ? 'selected' : '' ?>><?= $escape(\SimpleStore\Product\PurchasePartnerRepository::label($partner) . ($partner['ico'] !== '' ? ' · ' . $partner['ico'] : '') . ($missing !== [] ? ' · rozpracovaný' : '')) ?></option>
              <?php endforeach; ?>
            </select></label>
            <div class="sale-links"><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($partnerAdminUrl) ?>" target="_blank" rel="noopener">Seznam a úpravy prodávajících ↗</a>
              <a data-sale-partner-edit data-edit-base="<?= $escape($partnerAdminUrl) ?>" href="<?= \SimpleStore\Navigation\PanelUrl::escape($partnerAdminUrl . '&id=' . (int) ($selectedPartner['id'] ?? 0) . '#partner-form') ?>" target="_blank" rel="noopener" <?= $selectedPartner === null ? 'hidden' : '' ?>>Upravit vybraného ↗</a></div>
            <p class="sale-hint" data-sale-partner-status><?= $escape($selectedPartner === null ? 'Vyber prodávajícího nebo vyplň nového.' : (\SimpleStore\Product\PurchasePartnerRepository::missing($selectedPartner) === [] ? 'Údaje prodávajícího jsou kompletní.' : 'Rozpracovaný profil – doplň chybějící údaje.')) ?></p>
          </div>
          <div>
            <label>Cena dodavatele <span class="sale-money-input"><input type="number" name="supplier_price_czk" min="1" max="9999999" step="1" inputmode="numeric" value="<?= $escape($saleValues['supplier_price_czk'] ?? '') ?>" aria-describedby="sale-price-help"><span>Kč / ks</span></span></label>
            <p class="sale-hint" id="sale-price-help">Celé Kč. Základní cena produktu: <strong data-sale-base-price><?= $money($basePrice) ?></strong>.</p>
            <p class="sale-fee">Odměna za službu <output data-sale-fee><?= $fee === null ? '—' : $money($fee) ?></output></p>
          </div>
        </div>
        <p class="sale-notice sale-notice--draft" data-sale-readiness <?= $saleMissing === [] ? 'hidden' : '' ?>><?= $saleMissing !== [] ? $escape('Rozpracováno: doplň ' . implode(', ', $saleMissing) . '. Uložit můžeš i nyní, objednávka bude možná po doplnění.') : '' ?></p>
        <div class="sale-extras">
          <details class="sale-disclosure sale-new-partner" data-sale-new <?= $hasNewPartner ? 'open' : '' ?>>
            <summary>Nový prodávající</summary>
            <div class="sale-disclosure-body"><p class="sale-hint">Nový profil se uloží do seznamu a vybere pro tento produkt. Stačí i rozpracované údaje.</p>
              <div class="sale-partner-fields"><?php $partnerPrefix = 'partner_'; require __DIR__ . '/purchase-partner-fields.php'; ?></div>
              <button type="submit" name="create_partner" value="1" class="sale-button sale-button--secondary">Uložit nového prodávajícího a nastavení</button>
            </div>
          </details>
          <?php if ($saleVariants !== []): ?><details class="sale-disclosure sale-variant-prices"><summary>Nákupní ceny variant <span><?= count($saleVariants) ?></span></summary>
            <div class="sale-disclosure-body"><p class="sale-hint">Všechny varianty mohou přebírat jednu cenu dodavatele výše. Tady vyplň jen odlišné nákupní ceny; prázdné pole se přepočítá i při další změně společné ceny.</p>
              <button type="submit" name="inherit_variant_prices" value="1" data-sale-inherit-prices class="sale-button sale-button--secondary">Použít společnou nákupní cenu u všech variant</button>
              <div class="sale-variant-table"><table><thead><tr><th>Varianta</th><th>Cena pro zákazníka</th><th>Cena dodavatele Kč</th><th>Odměna</th></tr></thead><tbody>
              <?php foreach ($saleVariants as $saleVariant): $variantPrice = (int) ($saleVariant['price_czk'] ?? $basePrice); ?>
                <tr data-sale-variant data-price="<?= $variantPrice ?>" data-enabled="<?= $saleVariant['enabled'] ? '1' : '0' ?>" data-base="<?= $saleVariant['price_czk'] === null ? '1' : '0' ?>">
                  <th scope="row"><label for="sale-cost-<?= $escape($saleVariant['id']) ?>"><?= $escape(implode(' / ', $saleVariant['choices'])) ?><?= $saleVariant['enabled'] ? '' : ' (nenabízí se)' ?></label></th>
                  <td data-sale-variant-retail><?= $money($variantPrice) ?></td><td><input id="sale-cost-<?= $escape($saleVariant['id']) ?>" type="number" min="1" max="9999999" step="1" inputmode="numeric" name="variant_price[<?= $escape($saleVariant['id']) ?>]" value="<?= $escape($saleValues['variant_prices'][$saleVariant['id']] ?? '') ?>" placeholder="Z produktu"></td><td><output>—</output></td>
                </tr>
              <?php endforeach; ?></tbody></table></div>
            </div>
          </details><?php endif; ?>
        </div>
        <p class="sale-hint sale-explanation">Odměna je již v konečné ceně. Zákazník uvidí rozpis a prodávajícího v souhrnu objednávky; cena se nezvýší.</p>
      </div>
      <footer class="sale-footer"><div><p class="sale-save-state" data-sale-save-state role="status"><?= ($productSaleError ?? '') !== '' ? 'Změny nejsou uložené' : 'Nastavení odpovídá uloženým údajům' ?></p><p class="sale-hint">Lze uložit i rozpracované. Přepnutí prodeje zachová partnerské údaje.</p></div>
        <button type="submit" class="sale-button">Uložit způsob prodeje</button></footer>
    </form>
    <script defer src="<?= $escape($siteRoot . 'assets/product-sale.js?v=' . filemtime(__DIR__ . '/../../assets/product-sale.js')) ?>"></script>
  <?php endif; ?>
</section>
<?php endif; ?>
