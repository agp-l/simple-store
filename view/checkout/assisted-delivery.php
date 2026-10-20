<?php
// Use only the priced cart / immutable order snapshot, including historical promises.
$assistedDeliveryNotes = [];
foreach ($checkoutItems as $deliveryItem) {
    if (($deliveryItem['sale_mode'] ?? 'own') !== 'assisted') continue;
    $note = (string) ($deliveryItem['delivery_note'] ?? '');
    if ($note === '') continue;
    $assistedDeliveryNotes[$note][] = $deliveryItem['name'];
}
?>
<?php if ($assistedDeliveryNotes !== []): ?>
<details class="assisted-details assisted-delivery">
  <summary>Obstarání nákupu a doručení</summary>
  <div class="assisted-details-body">
    <?php foreach ($assistedDeliveryNotes as $note => $names): ?>
      <p><?php if (count($assistedDeliveryNotes) > 1): ?><strong><?= $checkoutEscape(implode(', ', $names)) ?>:</strong> <?php endif; ?><?= $checkoutEscape($note) ?></p>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>
