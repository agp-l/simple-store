<?php if (($item['sale_mode'] ?? 'own') === 'assisted'): ?>
<div class="assisted-item">
  <p class="assisted-item-kind">Asistovaný nákup · prodává <strong><?= $checkoutEscape($item['seller']['name']) ?></strong></p>
  <p class="assisted-breakdown">Za kus: zboží <span><?= number_format((int) $item['supplier_price_czk'], 0, ',', ' ') ?> Kč</span> + služba <span><?= number_format((int) $item['assistance_fee_czk'], 0, ',', ' ') ?> Kč</span> <span>(již v ceně)</span></p>
  <details class="assisted-details">
    <summary>Údaje prodávajícího a podmínky</summary>
    <div class="assisted-details-body">
      <p>Nákup u tohoto podnikatele obstaráváme vaším jménem. Za zboží odpovídá prodávající, za službu asistence my.</p>
      <dl>
        <div><dt>Prodávající</dt><dd><?= $checkoutEscape($item['seller']['name']) ?> · IČO <?= $checkoutEscape($item['seller']['ico']) ?></dd></div>
        <div><dt>Sídlo</dt><dd><?= $checkoutEscape($item['seller']['address']) ?></dd></div>
        <div><dt>Kontakt</dt><dd><?= $checkoutEscape($item['seller']['email']) ?></dd></div>
      </dl>
      <a href="<?= $checkoutEscape($item['seller']['terms_url']) ?>" target="_blank" rel="noopener noreferrer">Podmínky prodávajícího ↗</a>
    </div>
  </details>
</div>
<?php endif; ?>
