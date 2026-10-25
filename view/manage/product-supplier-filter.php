<?php $catalogSupplierState ??= 'all'; ?>
<form class="catalog-supplier-filter" method="get" action="<?= $escape($searchAction) ?>#produkty">
  <input type="hidden" name="manage" value="1"><input type="hidden" name="visibility" value="<?= $escape($catalogVisibility) ?>"><input type="hidden" name="search" value="<?= $escape($searchTerm) ?>">
  <label><span id="catalog-supplier-label">Prodávající a dodavatelé</span><select name="supplier_state" aria-labelledby="catalog-supplier-label">
    <?php foreach (\SimpleStore\Product\ProductRepository::SUPPLIER_FILTERS as $filterValue => $filterLabel): ?>
      <option value="<?= $filterValue ?>" <?= $catalogSupplierState === $filterValue ? 'selected' : '' ?>><?= $filterLabel ?></option>
    <?php endforeach; ?>
  </select></label>
  <button type="submit">Filtrovat produkty</button>
  <?php if ($catalogSupplierState !== 'all'): ?><a href="<?= $escape($searchAction . '?' . http_build_query(['manage' => '1', 'visibility' => $catalogVisibility, 'search' => $searchTerm])) ?>#produkty">Zrušit filtr dodavatelů</a><?php endif; ?>
  <p>„Bez prodávajícího“ znamená prázdný výběr; rozpracovaný profil už je přiřazený. Dodavatelé jsou odkazy uložené u produktu.</p>
</form>
