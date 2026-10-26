<?php
$partnerVariantKey = $variant['id'];
$partnerLinks = $variantLinksById[$partnerVariantKey] ?? [];
$partnerSourceOnly = false;
require __DIR__.'/product-partner-links.php';
