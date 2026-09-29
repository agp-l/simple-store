<?php
declare(strict_types=1);

// Copy to config/checkout.php to set the real bank account, legal page and shipping price.
// Home delivery is enabled for development; adjust its price before taking orders.
return [
    'local_test_checkout' => true, // Only on localhost in debug mode; no payment is requested.
    'terms_url' => '', // Local published page, e.g. /cs/obchodni-podminky (adjust for subdirectory).
    'bank_transfer' => [
        'iban' => '', // Calculated from the Czech account number if left empty.
        'account_display' => '', // Enter in administration; do not commit a private account.
        'recipient' => '',
        'payment_due_days' => 7,
    ],
    'shipping_methods' => [
        // Prices are whole CZK.
        'home' => [
            'label' => 'Doručení na adresu',
            'price_czk' => 99,
            'requires_address' => true,
        ],
        // Pickup requires a verified pickup-point selector; the current checkout offers home only.
        // 'pickup' => [
        //     'label' => 'Výdejní místo',
        //     'price_czk' => 69,
        //     'requires_address' => false,
        // ],
    ],
];
