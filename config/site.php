<?php
declare(strict_types=1);

return [
    // Show PHP errors and application exceptions while building the project.
    'debug' => true,
    'default_language' => 'cs',
    // Add another language only after its interface texts have been translated.
    'languages' => ['cs'],
    'customer_registration' => true,
    // Keep the newest 50 snapshots per product/page/post and language.
    'revision_limit' => 50,
];
