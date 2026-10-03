<?php
declare(strict_types=1);

use SimpleStore\Product\ProductDetails;
use SimpleStore\Product\ProductInlineEditor;
use SimpleStore\Rendering\PageRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

$form = ProductInlineEditor::starter('cs', 'boty');
$form = ProductInlineEditor::change($form, 'set', 'name', 'Pánské trailové boty');
if ($form['slug'] !== 'panske-trailove-boty') {
    throw new RuntimeException('The first product name edit must replace the starter URL.');
}
$form = ProductInlineEditor::change($form, 'set', 'slug', 'vlastni-adresa');
$form = ProductInlineEditor::change($form, 'set', 'name', 'Druhé jméno');
if ($form['slug'] !== 'vlastni-adresa') {
    throw new RuntimeException('Changing the product name overwrote a custom URL.');
}
$form = ProductInlineEditor::change($form, 'set', 'name', 'Lehká bota');
$form = ProductInlineEditor::change($form, 'set', 'summary', str_repeat('a', 180));
try {
    ProductInlineEditor::change($form, 'set', 'summary', str_repeat('č', 181));
    throw new RuntimeException('An oversized product card summary was accepted.');
} catch (InvalidArgumentException $expected) {
}
$form = ProductInlineEditor::change($form, 'section.add', '', 'list', 0);
if ($form['section_type'][1] !== 'list' || count($form['section_type']) !== 5) {
    throw new RuntimeException('A new block was not inserted after the selected block.');
}
$form = ProductInlineEditor::change($form, 'section.set', 'section_body', "Lehká\nPohodlná", 1);
$form = ProductInlineEditor::change($form, 'section.move', '', 'down', 1);
if ($form['section_body'][2] !== "Lehká\nPohodlná") {
    throw new RuntimeException('Moving a block lost its content.');
}
$form = ProductInlineEditor::change($form, 'section.remove', '', '', 2);
$form = ProductInlineEditor::change($form, 'option.add');
$form = ProductInlineEditor::change($form, 'option.set', 'option_name', 'Velikost', 1);
$form = ProductInlineEditor::change($form, 'option.set', 'option_values', "42 EU\n43 EU", 1);
$form = ProductInlineEditor::change($form, 'spec.set', 'spec_value', '292 g', 0);
$form = ProductInlineEditor::change($form, 'gallery.add', '', 'images/bota-bok.webp');
$form = ProductInlineEditor::change($form, 'set', 'published', '1');
$snapshot = ProductDetails::fromForm($form);
if ($form['name'] !== 'Lehká bota' || $form['published'] !== true ||
    count($snapshot['sections']) !== 4 || $snapshot['options'][1]['values'] !== ['42 EU', '43 EU'] ||
    $snapshot['specifications'][0]['value'] !== '292 g' || $snapshot['gallery'] !== ['images/bota-bok.webp']) {
    throw new RuntimeException('A small edit failed to preserve the complete product snapshot.');
}
$revision = array_merge($form, ['category' => 'boty', 'subcategory' => '',
    'details_json' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
if (ProductInlineEditor::fromRevision($revision)['section_body'] !== $form['section_body']) {
    throw new RuntimeException('A saved revision cannot be reopened for editing.');
}
try {
    ProductInlineEditor::change($form, 'section.remove', '', '', 500);
    throw new RuntimeException('An out-of-range block was accepted.');
} catch (InvalidArgumentException $expected) {
    // Invalid indexes must never modify a revision.
}

$revision['product_key'] = str_repeat('a', 32);
$revision['revision_number'] = 3;
$revision['stock_quantity'] = 12;
$revision['availability_status'] = 'in_stock';
$data = [
    'basePath' => '/shop/', 'language' => 'cs', 'product' => $revision,
    'categoryLabels' => ['boty' => 'Boty'],
    'categoryTrail' => [['path' => 'boty', 'title' => 'Boty']],
];
$renderer = new PageRenderer(dirname(__DIR__) . '/view');
ob_start();
$renderer->render('product-record', $data);
$public = ob_get_clean();
ob_start();
$renderer->render('product-record', $data + [
    'canEditProduct' => true, 'editMode' => true,
    'stockReady' => true,
    'editToken' => 'test-token',
    'editorCategories' => [['path' => 'boty', 'title' => 'Boty']],
]);
$editor = ob_get_clean();
$editableSectionCount = count(array_filter($snapshot['sections'],
    static fn (array $section): bool => $section['type'] !== 'image'));
$compactEditableBodies = preg_match_all('/<div class="inline-block-text"[^>]*><(?:p>|ul\b|div\b)/', $editor) ===
    $editableSectionCount && preg_match('/<div class="inline-block-text"[^>]*>(<p>.*?)<\/div>/s',
    $editor, $firstBody) === 1 && str_ends_with($firstBody[1], '</p>');
$checks = [
    'no_public_editor_config' => !str_contains($public, 'inline-editor-config'),
    'no_public_token' => !str_contains($public, 'test-token'),
    'edit_body' => str_contains($editor, 'data-edit-field="section_body"'),
    'admin_navigation' => str_contains($editor, 'aria-label="Pohledy na produkty"') &&
        str_contains($editor, 'href="/shop/cs?homepage_edit=1#homepage-editor"'),
    'product_settings' => str_contains($editor, '<section class="product-admin-settings"') &&
        str_contains($editor, 'Nastavení produktu') && str_contains($editor, 'Volné: 12 ks') &&
        str_contains($editor, 'data-editor-input="slug"') &&
        str_contains($editor, '<details class="inline-delete-product"'),
    'summary_limit' => str_contains($editor, 'data-edit-maxlength="180"') &&
        str_contains($editor, 'Perex na kartě'),
    'single_create_product' => substr_count($editor, 'value="create-product"') === 1,
    'compact_edit_body' => $compactEditableBodies,
    'add_section' => str_contains($editor, 'data-editor-action="section-add"'),
    'media_link' => str_contains($editor, 'section=media&amp;type=product&amp;key=' . str_repeat('a', 32)),
    'delete_product' => str_contains($editor, 'value="delete-product"'),
    'set_stock' => str_contains($editor, 'value="set-product-stock"'),
    'stock_quantity' => str_contains($editor, 'value="12"'),
    'no_public_stock_form' => !str_contains($public, 'value="set-product-stock"'),
    'no_public_quantity' => !str_contains($public, '12 ks'),
    'no_public_delete' => !str_contains($public, 'value="delete-product"'),
    'editor_token' => str_contains($editor, 'test-token'),
];
foreach ($checks as $name => $valid) {
    if (!$valid) throw new RuntimeException('Inline product rendering failed: ' . $name);
}
echo "Inline product editor tests passed.\n";
