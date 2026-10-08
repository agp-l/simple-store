<?php
declare(strict_types=1);

return [
    // Opt in only on a local development server; public hosting hides error details.
    'debug' => getenv('SIMPLE_STORE_DEBUG') === '1',
    'default_language' => 'cs',
    // Add another language only after its interface texts have been translated.
    'languages' => ['cs'],
    'customer_registration' => true,
    // Keep the newest 50 snapshots per product/page/post and language.
    'revision_limit' => 50,
];
