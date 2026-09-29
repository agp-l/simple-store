<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
?>
<main class="wrap checkout-page" id="produkty">
  <div class="checkout-heading"><span class="checkout-eyebrow">Vaše objednávka</span><h1>Košík</h1><p>Zkontrolujte produkty a počet kusů před pokračováním.</p></div>
  <?php require __DIR__ . '/steps.php'; ?>
  <?php if ($error !== ''): ?><p class="checkout-alert" role="alert"><?= $checkoutEscape($error) ?></p><?php endif; ?>
  <?php foreach ($checkoutOtherIssues as $issue): ?><p class="checkout-alert" role="alert"><?= $checkoutEscape($issue) ?></p><?php endforeach; ?>
  <?php if ($checkoutItems === []): ?>
    <section class="checkout-empty"><h2>Košík je prázdný</h2><p>Vyberte si vybavení do další výpravy.</p><a class="checkout-primary" href="<?= $checkoutEscape($siteRoot . $language) ?>#produkty">Prohlédnout produkty</a></section>
  <?php else: ?>
    <div class="checkout-columns">
      <section class="checkout-panel" aria-label="Produkty v košíku">
        <ul class="checkout-lines">
          <?php foreach ($checkoutItems as $item):
              $productUrl = ($item['slug'] ?? '') !== ''
                  ? $siteRoot . $language . '/produkt/' . rawurlencode((string) $item['slug']) : null;
              $imagePath = (string) ($item['image_path'] ?? '');
              $imageUrl = str_starts_with($imagePath, 'images/') ? $siteRoot . $imagePath : $imagePath;
          ?>
          <li class="checkout-line">
            <?php if ($productUrl !== null): ?>
              <a class="checkout-line-image" href="<?= $checkoutEscape($productUrl) ?>" aria-label="<?= $checkoutEscape($item['name']) ?>"><?php if ($imageUrl !== ''): ?><img src="<?= $checkoutEscape($imageUrl) ?>" alt="" loading="lazy" width="110" height="110"><?php endif; ?></a>
            <?php else: ?>
              <span class="checkout-line-image" aria-hidden="true"><?php if ($imageUrl !== ''): ?><img src="<?= $checkoutEscape($imageUrl) ?>" alt="" loading="lazy" width="110" height="110"><?php endif; ?></span>
            <?php endif; ?>
            <div class="checkout-line-detail">
              <?php if ($productUrl !== null): ?><a class="checkout-line-title" href="<?= $checkoutEscape($productUrl) ?>"><?= $checkoutEscape($item['name']) ?></a><?php else: ?><strong class="checkout-line-title"><?= $checkoutEscape($item['name']) ?></strong><?php endif; ?>
              <?php foreach (($item['options'] ?? []) as $label => $value): ?><small><?= $checkoutEscape($label) ?>: <?= $checkoutEscape($value) ?></small><?php endforeach; ?>
              <small><?= $item['unit_price_czk'] === null ? 'Cena není dostupná' : $checkoutMoney((int) $item['unit_price_czk']) . ' / ks' ?></small>
              <?php if (($item['issue'] ?? '') !== ''): ?><p class="checkout-line-issue" role="alert"><?= $checkoutEscape($item['issue']) ?></p><?php endif; ?>
              <div class="checkout-line-actions">
                <form method="post" action="<?= $checkoutEscape($cartUrl) ?>">
                  <input type="hidden" name="csrf" value="<?= $checkoutEscape($cartToken) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="line_id" value="<?= $checkoutEscape($item['line_id']) ?>">
                  <label for="cart-qty-<?= $checkoutEscape($item['line_id']) ?>">Počet</label>
                  <input id="cart-qty-<?= $checkoutEscape($item['line_id']) ?>" type="number" name="quantity" value="<?= (int) $item['quantity'] ?>" min="1" max="99" required inputmode="numeric">
                  <button type="submit" class="checkout-quiet">Uložit</button>
                </form>
                <form method="post" action="<?= $checkoutEscape($cartUrl) ?>">
                  <input type="hidden" name="csrf" value="<?= $checkoutEscape($cartToken) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="line_id" value="<?= $checkoutEscape($item['line_id']) ?>">
                  <button type="submit" class="checkout-text-button" aria-label="Odebrat <?= $checkoutEscape($item['name']) ?>">Odebrat</button>
                </form>
              </div>
            </div>
            <strong class="checkout-line-price"><?= $item['line_total_czk'] === null ? 'Nedostupné' : $checkoutMoney((int) $item['line_total_czk']) ?></strong>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <div class="checkout-sidebar">
        <?php require __DIR__ . '/summary.php'; ?>
        <?php if ($checkoutCanContinue && ($checkoutAvailable ?? false) && $availableShippingOptions !== []): ?><a class="checkout-primary" href="<?= $checkoutEscape($checkoutUrl . '?step=shipping') ?>">Pokračovat k dopravě <span aria-hidden="true">→</span></a>
        <?php elseif (!($shippingConfigured ?? false) || $availableShippingOptions === []): ?><p class="checkout-alert" role="alert">Doprava na adresu zatím není nastavená. Objednávku teď nelze dokončit.</p>
        <?php elseif (!($checkoutAvailable ?? false)): ?><p class="checkout-alert" role="alert">Platba nebo obchodní podmínky zatím nejsou nastavené. Objednávku teď nelze dokončit.</p>
        <?php else: ?><p class="checkout-fineprint">Před pokračováním upravte produkty označené upozorněním.</p><?php endif; ?>
        <a class="checkout-back" href="<?= $checkoutEscape($siteRoot . $language) ?>#produkty">← Pokračovat v nákupu</a>
      </div>
    </div>
  <?php endif; ?>
</main>
