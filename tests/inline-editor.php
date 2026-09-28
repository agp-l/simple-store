<?php
declare(strict_types=1);

use SimpleStore\Product\ProductDetails;
use SimpleStore\Product\ProductInlineEditor;
use SimpleStore\Rendering\PageRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

$form = ProductInlineEditor::starter('cs', 'boty');
$form = ProductInlineEditor::change($form, 'set', 'name', 'Lehká bota');
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
    'editToken' => 'test-token',
    'editorCategories' => [['path' => 'boty', 'title' => 'Boty']],
]);
$editor = ob_get_clean();
if (str_contains($public, 'inline-editor-config') || str_contains($public, 'test-token') ||
    !str_contains($editor, 'data-edit-field="section_body"') ||
    !str_contains($editor, 'data-editor-action="section-add"') ||
    !str_contains($editor, 'test-token')) {
    throw new RuntimeException('The on-page controls leaked publicly or were missing from editor preview.');
}
echo "Inline product editor tests passed.\n";
