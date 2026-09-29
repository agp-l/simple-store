<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Navigation\Slugger;
use SimpleStore\Product\ProductDetails;
use SimpleStore\Product\ProductText;

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$check(Slugger::fromTitle('Pánské boty – žlutá špička 42,5 EU') === 'panske-boty-zluta-spicka-42-5-eu',
    'Czech product title was not transliterated.');
$check(Slugger::fromTitle('Články o cestách') === 'clanky-o-cestach', 'Czech page title failed.');

$details = ProductDetails::fromForm([
    'gallery' => "images/bota-bok.webp\nhttps://example.org/bota.jpg",
    'option_name' => ['Barva', 'Velikost', 'Pozice zipu'],
    'option_values' => ["Grey / Clay\nBlack", "42 EU\n43 EU", "Levá\nPravá"],
    'spec_name' => ['Drop', 'Hmotnost'], 'spec_value' => ['3 mm', '292 g'],
    'section_type' => ['text', 'list', 'table'],
    'section_heading' => ['O botě', 'Vlastnosti', 'Přehled'],
    'section_body' => ['První odstavec.', "Lehká\nOdolná", "Materiál | Síťovina\nDrop | 3 mm"],
]);
$check(count($details['options']) === 3 && $details['options'][2]['values'][1] === 'Pravá',
    'The product selection groups were lost.');
$check(count($details['sections']) === 3 && count($details['gallery']) === 2, 'Gallery or blocks were lost.');
$roundTrip = ProductDetails::decode(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$check($roundTrip === $details, 'The product details snapshot did not round-trip.');
$check(ProductDetails::decode(null, '42 EU, 43 EU')['options'][0]['values'] === ['42 EU', '43 EU'],
    'Old product sizes are missing.');
$damaged = $details;
$damaged['sections'][0] = ['type' => 'image', 'heading' => 'Foto', 'body' => 'javascript:alert(1)'];
$check(ProductDetails::decode(json_encode($damaged, JSON_THROW_ON_ERROR), '42 EU')['sections'] === [],
    'A damaged snapshot must not render an unsafe image URL.');
$check(ProductDetails::decode('{"options":"not an array","specifications":[],"sections":[],"gallery":[]}')['options'] === [],
    'A malformed snapshot should safely fall back to empty details.');
$product = [
    'image_path' => 'images/bota.webp', 'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE),
    'sizes' => '', 'category' => 'boty', 'name' => 'Bota <script>alert(1)</script>',
    'brand' => 'Výrobce', 'summary' => 'Na cesty', 'price_czk' => 3990,
    'stock_status' => 'in_stock', 'description' => 'Pohodlná bota.',
];
$basePath = '/simple-store/';
$siteRoot = $basePath;
$language = 'cs';
$categoryTrail = [['path' => 'boty', 'title' => 'Boty']];
$categoryLabels = ['boty' => 'Boty'];
ob_start();
require dirname(__DIR__) . '/view/product-record.php';
$html = ob_get_clean();
$check(str_contains($html, 'product-option-2') && str_contains($html, 'Technické údaje'),
    'The product detail did not render choices and specifications.');
$check(!str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'),
    'The product title was not escaped.');
$check(substr_count($html, 'data-gallery-image=') === 3 &&
    str_contains($html, 'id="detail-image-open"') &&
    str_contains($html, 'id="detail-lightbox"') &&
    str_contains($html, 'id="detail-lightbox-image"'),
    'A gallery with multiple photographs must expose its thumbnails and fullscreen image control.');
$formatted = ProductText::inline('**Skvělé** <script> [více](https://example.org/?q=1&v=2)');
$check(str_contains($formatted, '<strong>Skvělé</strong>') &&
    str_contains($formatted, '&lt;script&gt;') && str_contains($formatted, 'q=1&amp;v=2'),
    'Safe formatting failed.');
try {
    ProductDetails::fromForm(['option_name' => ['Barva'], 'option_values' => ["Černá\nČerná"]]);
    throw new RuntimeException('Duplicate choices were accepted.');
} catch (InvalidArgumentException $expected) {
    // Invalid combinations should fail before a revision is written.
}

echo "Product details and slug tests passed.\n";
