<?php
declare(strict_types=1);

// Copy to config/checkout.php to set the real bank account, legal page and shipping price.
// Home delivery is enabled for development; adjust its price before taking orders.
return [
    'terms_url' => '', // Local published page, e.g. /cs/obchodni-podminky (adjust for subdirectory).
    'bank_transfer' => [
        'iban' => '',             // Czech IBAN in CZK, with valid check digits.
        'account_display' => '',  // Domestic account number as customers see it, e.g. 123456789/0100.
        'recipient' => '',        // Account holder shown on the payment page.
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
