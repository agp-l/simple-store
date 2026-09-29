<?php
declare(strict_types=1);

$steps = [
    'cart' => ['label' => 'Košík', 'url' => $cartUrl],
    'shipping' => ['label' => 'Doprava a údaje', 'url' => $checkoutUrl . '?step=shipping'],
    'payment' => ['label' => 'Platba', 'url' => $checkoutUrl . '?step=payment'],
    'review' => ['label' => 'Kontrola', 'url' => $checkoutUrl . '?step=review'],
];
$currentStep = array_search($step, array_keys($steps), true);
?>
<nav aria-label="Postup objednávky" class="checkout-progress">
  <ol>
    <?php foreach ($steps as $index => $entry): $position = array_search($index, array_keys($steps), true); ?>
      <li class="<?= $position === $currentStep ? 'is-current' : ($position < $currentStep ? 'is-done' : '') ?>">
        <?php if ($position < $currentStep): ?><a href="<?= $checkoutEscape($entry['url']) ?>"><span class="checkout-step-number" aria-hidden="true"><?= $position + 1 ?></span><?= $checkoutEscape($entry['label']) ?></a>
        <?php else: ?><span <?= $position === $currentStep ? 'aria-current="step"' : '' ?>><span class="checkout-step-number" aria-hidden="true"><?= $position + 1 ?></span><?= $checkoutEscape($entry['label']) ?></span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>
