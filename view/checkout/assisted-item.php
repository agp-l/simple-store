<?php if (($item['sale_mode'] ?? 'own') === 'assisted'): ?>
<small class="assisted-breakdown">Asistovaný nákup · za kus: zboží <?= $checkoutMoney((int) $item['supplier_price_czk']) ?> + služba <?= $checkoutMoney((int) $item['assistance_fee_czk']) ?>. Služba je již zahrnuta v ceně.</small>
<small>Prodávající: <?= $checkoutEscape($item['seller']['name']) ?>, IČO <?= $checkoutEscape($item['seller']['ico']) ?>; <?= $checkoutEscape($item['seller']['address']) ?>; <?= $checkoutEscape($item['seller']['email']) ?>. <a href="<?= $checkoutEscape($item['seller']['terms_url']) ?>" target="_blank" rel="noopener noreferrer">Podmínky prodávajícího</a>.</small>
<small>Dodání: <?= $checkoutEscape($item['delivery_note']) ?></small>
<?php endif; ?>
