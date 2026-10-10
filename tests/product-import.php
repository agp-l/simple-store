<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Product\ProductImport;
use SimpleStore\Product\ProductDetails;
use SimpleStore\Admin\AdminActionPolicy;

$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call): void {
    try { $call(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid import was accepted.');
};
$json = file_get_contents(dirname(__DIR__) . '/src/Product/examples/flextail.json');
$data = ProductImport::decode($json);
$fields = ProductImport::fields($data);
$check($fields['name'] === 'Flextail ZERO Power 10000' && !$fields['published'], 'Sample must create a draft.');
$check(count(ProductDetails::fromForm($fields)['specifications']) === 7, 'Specifications were lost.');
$minimal = ['format' => ProductImport::FORMAT, 'language' => 'cs', 'product' => ['name' => 'Lehký batoh', 'category_path' => 'batohy', 'price_czk' => 2000]];
$created = ProductImport::fields(ProductImport::decode(json_encode($minimal)));
$check($created['slug'] === 'lehky-batoh' && ProductDetails::imagePath($created['image_path']), 'New product defaults failed.');
$current = array_merge($fields, ['product_key' => str_repeat('a', 32), 'revision_number' => 4,
    'category' => 'vybaveni', 'subcategory' => 'powerbanky', 'details_json' => json_encode(ProductDetails::fromForm($fields))]);
$export = ProductImport::decode(ProductImport::export($current));
$check(ProductImport::fields($export, $current) === $fields, 'Export/import changed the snapshot.');
$export['product'] = ['price_czk' => 1990];
$changed = ProductImport::fields($export, $current);
$check($changed['price_czk'] === '1990' && $changed['section_body'] === $fields['section_body'] && $changed['image_path'] === $fields['image_path'], 'Partial update destroyed unrelated data.');
$export['product']['sections'] = [];
$check(ProductImport::fields($export, $current)['section_body'] === [], 'Explicit empty list must clear blocks.');
$export['revision'] = 3;
$reject(fn () => ProductImport::fields($export, $current));
$reject(fn () => ProductImport::fields($export));
foreach (['{"format":"unknown","language":"cs","product":{}}', '[]', '{', str_repeat(' ', 131073), '{"format":"simple-store.product.v1","language":"cs","product":{},"sql":"DELETE FROM shop_product_revisions"}'] as $bad) {
    $reject(fn () => ProductImport::decode($bad));
}
foreach ([['published' => 'false'], ['price_czk' => '200'], ['image_path' => 'javascript:alert(1)'],
    ['summary' => str_repeat('ž', 181)], ['stock_status' => 'fake'], ['options' => [['name' => 'Barva', 'values' => [4]]]],
    ['sections' => [['type' => 'script', 'body' => 'bad']]], ['gallery' => 'url'], ['price' => 10]] as $bad) {
    $input = $minimal;
    $input['product'] = array_merge($input['product'], $bad);
    $reject(fn () => ProductImport::fields($input));
}
$check(ProductImport::decode("```json\n" . $json . "\n```") === $data, 'AI markdown fences must work.');
$check(AdminActionPolicy::allows('product-import-preview') && AdminActionPolicy::allows('product-import-save'), 'Admin actions are not routed through authentication.');
echo "Product import: creation, partial updates, export, conflicts and invalid input passed.\n";

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$importError = 'Chybný vstup';
$importJson = '</textarea><script>alert(1)</script>';
$importPreview = null;
$adminUrl = '/admin.php';
$csrf = 'test-token';
$language = 'cs';
$importCategories = [];
$importTab = 'single';
$transferError = '';
$zipState = null;
$zipError = '';
$basePath = '/';
ob_start();
require dirname(__DIR__) . '/view/admin/product-import.php';
$html = ob_get_clean();
$check(!str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;/textarea&gt;'), 'Invalid input must survive without HTML injection.');
$check(str_contains($html, 'name="csrf"') && !str_contains($html, 'value="product-import-save"'), 'Unvalidated form must not offer save.');
echo "Product import view: escaped input retained and preview-only initial form passed.\n";
$withSupplier = $minimal;
$withSupplier['product']['suppliers'] = [['label' => 'Gramino', 'url' => 'https://gramino.cz/produkt/topo-athletic-pursuit-3-mens/']];
$check(ProductImport::suppliers($withSupplier)[0]['label'] === 'Gramino', 'Supplier list missing.');
$check(ProductImport::suppliers($minimal) === null, 'Omitted suppliers must remain unchanged.');
$withSupplier['product']['suppliers'] = [];
$check(ProductImport::suppliers($withSupplier) === [], 'Empty suppliers must clear the list.');
$withSupplier['product']['suppliers'] = [['label' => 'Bad', 'url' => 'javascript:alert(1)']];
$reject(fn () => ProductImport::fields($withSupplier));
$external = $minimal;
$external['product']['image_path'] = 'https://example.org/photo.jpg';
$reject(fn () => ProductImport::fields($external));
$many = $minimal;
$many['product']['gallery'] = array_map(static fn (int $n): string => 'images/photo-' . $n . '.jpg', range(1, 30));
$check(count(ProductDetails::fromForm(ProductImport::fields($many))['gallery']) === 30, 'Gallery limit remains.');
echo "Suppliers, local-only new photos and unlimited gallery tests passed.\n";

$importTab = 'help';
$aiPrompt = '</textarea><script>alert(1)</script>';
$promptError = 'Validation error';
ob_start();
require dirname(__DIR__) . '/view/admin/product-import.php';
$html = ob_get_clean();
$check(!str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;/textarea&gt;'), 'Prompt editor must escape and retain invalid input.');
$check(str_contains($html, 'name="ai_prompt"') && str_contains($html, 'value="product-prompt-save"') && str_contains($html, 'name="csrf"'), 'Editable prompt form must use authenticated POST.');
$check(AdminActionPolicy::allows('product-prompt-save'), 'Prompt save is not allowed for administrators.');
echo "Editable AI prompt view: safe text retention and authenticated form passed.\n";
