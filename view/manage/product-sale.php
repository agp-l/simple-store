<?php if ($editing): ?>
<section class="supplier-links" id="product-sale" aria-label="Způsob prodeje">
  <h2>Způsob prodeje <small>Jen pro správce</small></h2>
  <?php if (!($productSalesReady ?? false)): ?>
    <p><a href="<?= $escape($siteRoot . 'admin.php?section=database') ?>">Nejprve aktualizuj databázi.</a></p>
  <?php else: ?>
    <?php if (($_GET['sale_saved'] ?? '') === '1'): ?><p role="status">Nastavení prodeje bylo uloženo.</p><?php endif; ?>
    <?php if (isset($_GET['sale_error']) && is_string($_GET['sale_error'])): ?><p role="alert"><?= $escape($_GET['sale_error']) ?></p><?php endif; ?>
    <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>" class="supplier-form">
      <input type="hidden" name="action" value="product-sale"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
      <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
      <input type="hidden" name="version" value="<?= (int) ($productSale['version'] ?? 0) ?>">
      <fieldset><legend>Kdo prodává zboží</legend>
        <label><input type="radio" name="mode" value="own" <?= ($productSale['mode'] ?? 'own') === 'own' ? 'checked' : '' ?>> Prodáváme my</label>
        <label><input type="radio" name="mode" value="assisted" <?= ($productSale['mode'] ?? '') === 'assisted' ? 'checked' : '' ?>> Asistovaný nákup u partnera</label>
      </fieldset>
      <p>Konečná cena produktu se nezvyšuje. Cena dodavatele se odečte a rozdíl je odměna za službu. Níže uvedené údaje vyplň pouze pro asistovaný nákup.</p>
      <label>Cena dodavatele za kus (celé Kč) <input type="number" name="supplier_price_czk" min="1" max="9999999" step="1" value="<?= $escape($productSale['supplier_price_czk'] ?? '') ?>"></label>
      <?php if ($product['variant_inventory']['enabled'] ?? false): ?><details><summary>Odlišné nákupní ceny variant</summary>
        <p>Prázdné pole použije nákupní cenu produktu.</p>
        <?php foreach ($product['variant_inventory']['items'] as $saleVariant): ?>
          <label><?= $escape(implode(' / ', $saleVariant['choices'])) ?> <input type="number" min="1" max="9999999" step="1" name="variant_price[<?= $escape($saleVariant['id']) ?>]" value="<?= $escape($productSale['variant_prices'][$saleVariant['id']] ?? '') ?>"></label>
        <?php endforeach; ?></details><?php endif; ?>
      <label>Předpokládaná doba dodání zákazníkovi <input name="delivery_note" maxlength="190" value="<?= $escape($productSale['delivery_note'] ?? '') ?>" placeholder="Např. do 5 pracovních dnů od zaplacení"></label>
      <label>Vybraný prodávající <select name="partner_id"><option value="0">Nový prodávající – vyplnit níže</option>
        <?php foreach (($purchasePartners ?? []) as $partner): ?><option value="<?= (int) $partner['id'] ?>" <?= (int) ($productSale['partner_id'] ?? 0) === (int) $partner['id'] ? 'selected' : '' ?>><?= $escape($partner['name'] . ' · IČO ' . $partner['ico']) ?></option><?php endforeach; ?>
      </select></label>
      <details><summary>Nový prodávající</summary><p>Uloží se do seznamu pro další produkty. Opravu údajů ulož jako nový profil; již přijaté objednávky zůstanou beze změny.</p>
        <label>Obchodní firma / jméno podnikatele <input name="partner_name" maxlength="190"></label>
        <label>IČO <input name="partner_ico" inputmode="numeric" maxlength="8" pattern="[0-9]{8}"></label>
        <label>Sídlo a adresa pro stížnosti <input name="partner_address" maxlength="500"></label>
        <label>E-mail prodávajícího <input type="email" name="partner_email" maxlength="190"></label>
        <label>HTTPS adresa obchodních podmínek <input type="url" name="partner_terms_url" maxlength="1000"></label>
        <label><input type="checkbox" name="partner_confirmed" value="1"> Ověřil jsem, že partner umožňuje nákup jménem zákazníka a doklad vystaví zákazníkovi.</label>
      </details>
      <p>U asistovaného nákupu uvidí zákazník rozpis ceny, prodávajícího a dobu dodání v souhrnu objednávky. Soukromé zdrojové odkazy níže se nezveřejňují. Dostupnost a dopravu nastav podle skutečných možností partnera.</p>
      <button type="submit" class="inline-small">Uložit způsob prodeje</button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
