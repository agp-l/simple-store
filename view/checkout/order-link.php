<?php
declare(strict_types=1);
?>
<div class="checkout-return-link" data-order-link>
  <label for="checkout-order-link">Odkaz na objednávku</label>
  <div class="checkout-return-link-controls">
    <input id="checkout-order-link" type="text" readonly value="<?= $checkoutEscape($orderShareUrl) ?>" data-order-path="<?= $checkoutEscape($orderUrl) ?>">
    <button type="button" class="checkout-quiet" data-copy-order-link hidden>Kopírovat</button>
  </div>
  <span class="checkout-copy-feedback" role="status" aria-live="polite" data-copy-feedback></span>
</div>
