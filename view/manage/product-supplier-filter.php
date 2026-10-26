<?php $catalogSupplierState ??= 'all'; $catalogPartnerId ??= 0; $catalogPartners ??= []; ?>
<form class="catalog-supplier-filter" method="get" action="<?= $escape($searchAction) ?>#produkty">
  <input type="hidden" name="manage" value="1"><input type="hidden" name="visibility" value="<?= $escape($catalogVisibility) ?>"><input type="hidden" name="search" value="<?= $escape($searchTerm) ?>">
  <label><span id="catalog-partner-label">Partner / dodavatel</span><select name="partner_id" aria-labelledby="catalog-partner-label">
    <option value="0" <?= $catalogPartnerId === 0 ? 'selected' : '' ?>>Všichni partneři</option>
    <?php foreach ($catalogPartners as $catalogPartner): ?>
      <option value="<?= (int) $catalogPartner['id'] ?>" <?= $catalogPartnerId === (int) $catalogPartner['id'] ? 'selected' : '' ?>><?= $escape(\SimpleStore\Product\PurchasePartnerRepository::label($catalogPartner)) ?></option>
    <?php endforeach; ?>
  </select></label>
  <label><span id="catalog-supplier-label">Prodávající a dodavatelé</span><select name="supplier_state" aria-labelledby="catalog-supplier-label">
    <?php foreach (\SimpleStore\Product\ProductRepository::SUPPLIER_FILTERS as $filterValue => $filterLabel): ?>
      <option value="<?= $filterValue ?>" <?= $catalogSupplierState === $filterValue ? 'selected' : '' ?>><?= $filterLabel ?></option>
    <?php endforeach; ?>
  </select></label>
  <button type="submit">Filtrovat produkty</button>
  <?php if ($catalogSupplierState !== 'all' || $catalogPartnerId > 0): ?><a href="<?= $escape($searchAction . '?' . http_build_query(['manage' => '1', 'visibility' => $catalogVisibility, 'search' => $searchTerm])) ?>#produkty">Zrušit filtr dodavatelů</a><?php endif; ?>
  <p>Partner se hledá podle vybraného prodávajícího i uložených odkazů u produktu a jeho variant, včetně alternativních partnerů. „Bez prodávajícího“ znamená prázdný výběr; rozpracovaný profil se počítá jako přiřazený.</p>
</form>
