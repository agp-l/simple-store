<?php if ($variantInventory['enabled']): ?>
<details class="variant-create" <?= isset($variantForm['merge_preview']) ? 'open' : '' ?>>
  <summary><?= \SimpleStore\Rendering\Icon::render('package') ?> Převést samostatný produkt na variantu</summary>
  <p>Pro samostatně založenou velikost nebo barvu. Přenesou se její volné kusy, cena, hlavní fotografie a dodavatelé. Původní produkt i popis zůstanou uložené jako koncept; jeho veřejná adresa povede sem. Společný popis tohoto produktu se nezmění.</p>
  <?php if (isset($variantForm['merge_preview']) && is_array($variantForm['merge_preview'])): $merge = $variantForm['merge_preview']; ?>
  <div class="variant-merge-preview"><strong><?= $escape($merge['source_name']) ?></strong><p>→ <?= $escape(\SimpleStore\Product\ProductVariants::label($merge['variant']['choices'])) ?><br>Volné kusy: <?= (int) $merge['variant']['quantity'] ?> · Cena: <?= (int) $merge['variant']['price_czk'] ?> Kč</p></div>
  <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
    <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="merge"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="merge_plan" value="<?= $escape(json_encode($merge, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
    <label class="variant-confirm"><input type="checkbox" name="confirm" value="1" required> Potvrzuji, že jde o stejný výrobek v uvedeném provedení. Převádím všechny volné kusy a skrývám původní produkt ve všech jazycích.</label>
    <button class="inline-small" type="submit"><?= \SimpleStore\Rendering\Icon::render('check') ?> Potvrdit převod</button>
  </form>
  <?php endif; ?>
  <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>">
    <input type="hidden" name="action" value="product-variants"><input type="hidden" name="operation" value="merge-preview"><input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="expected" value="<?= $escape($variantInventory['expected']) ?>">
    <label>Adresa převáděného produktu (část za /produkt/)<input name="source_slug" required maxlength="190" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="bunda-modra-m" value="<?= $escape($variantForm['source_slug'] ?? '') ?>"></label>
    <label>Jeho konkrétní provedení<textarea name="merge_groups" rows="3" required maxlength="16000" placeholder="Velikost: M&#10;Barva: Modrá"><?= $escape($variantForm['merge_groups'] ?? '') ?></textarea></label>
    <button class="inline-small" type="submit"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit náhled převodu</button>
  </form>
</details>
<?php endif; ?>
