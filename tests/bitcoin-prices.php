<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Pricing\BitcoinPriceDisplay;
use SimpleStore\Pricing\BitcoinRateRepository;

$quote = BitcoinRateRepository::parseQuote(json_encode(['bitcoin' => [
    'czk' => 2000000, 'last_updated_at' => time() - 30,
]], JSON_THROW_ON_ERROR));
if ($quote === null || $quote['rate'] !== 2000000.0 ||
    (new BitcoinPriceDisplay($quote))->format(1000) !== '1 000 Kč (≈ 0.00050000 BTC)' ||
    (new BitcoinPriceDisplay(null))->format(1000) !== '1 000 Kč') {
    throw new RuntimeException('The storefront quote formatting is incorrect.');
}
$html = (new BitcoinPriceDisplay($quote))->html(1000);
if (!str_contains($html, '<span class="money-main">1 000 Kč</span>') ||
    !str_contains($html, 'class="price-secondary"') || !str_contains($html, '≈ 0.00050000 BTC') ||
    str_contains((new BitcoinPriceDisplay(null))->html(1000), 'price-secondary')) {
    throw new RuntimeException('The HTML price must separate optional BTC from authoritative CZK.');
}
foreach (['{}', '{"bitcoin":{"czk":0,"last_updated_at":1}}',
    json_encode(['bitcoin' => ['czk' => 2000000, 'last_updated_at' => time() - 7201]])] as $invalid) {
    if (BitcoinRateRepository::parseQuote($invalid) !== null) {
        throw new RuntimeException('A stale or invalid market quote was accepted.');
    }
}
echo "Bitcoin price display OK\n";
