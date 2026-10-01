<?php
declare(strict_types=1);

// Presentation helpers shared by the five checkout templates. All amounts come
// from the server's priced cart/order snapshot, never from form fields.
$checkoutEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$checkoutMoney = static fn (int $amount): string => $priceDisplay instanceof \SimpleStore\Pricing\BitcoinPriceDisplay
    ? $priceDisplay->format($amount) : number_format($amount, 0, ',', ' ') . ' Kč';
$checkoutItems = is_array($checkout['items'] ?? null) ? $checkout['items'] : [];
$checkoutIssues = is_array($checkout['issues'] ?? null) ? $checkout['issues'] : [];
$lineIssues = array_values(array_filter(array_column($checkoutItems, 'issue'), 'is_string'));
$checkoutOtherIssues = array_values(array_diff($checkoutIssues, $lineIssues));
$checkoutCanContinue = (bool) ($checkout['can_continue'] ?? false);
$checkoutSubtotal = isset($checkout['subtotal_czk']) ? (int) $checkout['subtotal_czk'] : null;
$availableShippingOptions = array_values(array_filter($shippingOptions ?? [], static fn (array $option): bool =>
    isset($option['code'], $option['price_czk']) && is_int($option['price_czk'])));
$chosenShipping = null;
foreach ($availableShippingOptions as $option) {
    if ($option['code'] === ($delivery['method'] ?? '')) {
        $chosenShipping = $option;
        break;
    }
}
$summaryShippingPrice = $chosenShipping === null ? null : $selectedShippingPrice;
