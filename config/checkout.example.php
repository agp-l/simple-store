<?php
declare(strict_types=1);

// Copy to config/checkout.php to set the bank account and legal page.
// Nine delivery services are editable in the administration.
return [
    'local_test_checkout' => true, // Only on localhost in debug mode; no payment is requested.
    'terms_url' => '', // Optional local published page, e.g. /cs/obchodni-podminky.
    'bank_transfer' => [
        'iban' => '', // Calculated from the Czech account number if left empty.
        'account_display' => '', // Enter in administration; do not commit a private account.
        'recipient' => '',
        'payment_due_days' => 7,
    ],
    'shipping_methods' => \SimpleStore\Checkout\ShippingPolicy::defaults(),
];
