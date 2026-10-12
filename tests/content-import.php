<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Admin\AdminActionPolicy;
use SimpleStore\Content\ContentBody;
use SimpleStore\Content\ContentImport;
use SimpleStore\Rendering\PageRenderer;

$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call): void {
    try { $call(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid content import was accepted.');
};
foreach (['page', 'post'] as $type) {
    $data = ['format' => ContentImport::FORMAT, 'type' => $type, 'language' => 'cs', 'content' => [
        'title' => 'Příběh z hor', 'summary' => 'Úvod v češtině', 'published' => true,
        'visible_in_menu' => true, 'menu_order' => 3,
        'sections' => [
            ['type' => 'text', 'heading' => 'Cesta', 'body' => "První řádek\n\n**Tučně** a [odkaz](https://example.org)."],
            ['type' => 'list', 'heading' => '', 'body' => "První\nDruhý"],
            ['type' => 'table', 'heading' => 'Etapy', 'body' => "Den | Km\n1 | 20"],
            ['type' => 'image', 'heading' => 'Fotografie', 'body' => 'images/batoh.webp'],
        ],
    ]];
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    $fields = ContentImport::fields(ContentImport::decode($json), ['cs', 'en'], 'new');
    $check($fields['slug'] === 'pribeh-z-hor' && !$fields['published'] && !$fields['visible_in_menu'], 'A new import must be a private draft with a generated slug.');
    $check(ContentBody::decode($fields['body']) === $data['content']['sections'], 'Blocks or Unicode were lost.');
    $row = $fields + ['document_key' => str_repeat('a', 32), 'revision_number' => 3];
    $row['published'] = true;
    $row['visible_in_menu'] = $type === 'page';
    $export = ContentImport::decode(ContentImport::export($row));
    $updated = ContentImport::fields($export, ['cs', 'en'], 'update', $row);
    $check($updated === array_intersect_key($row, $fields), 'Export/import changed a document snapshot.');
    $export['content'] = ['title' => 'Upravený příběh'];
    $updated = ContentImport::fields($export, ['cs', 'en'], 'update', $row);
    $check($updated['body'] === $row['body'] && $updated['slug'] === $row['slug'] && $updated['published'], 'Partial update destroyed existing fields.');
    $export['content']['sections'] = [];
    $check(ContentBody::decode(ContentImport::fields($export, ['cs'], 'update', $row)['body']) === [], 'Explicit empty sections must clear the body.');
    $reject(fn () => ContentImport::fields($export, ['cs'], 'update', array_replace($row, ['revision_number' => 4])));
    $reject(fn () => ContentImport::fields($export, ['cs'], 'update', array_replace($row, ['type' => $type === 'page' ? 'post' : 'page'])));
    $reject(fn () => ContentImport::fields($export, ['cs'], 'update', null));
    $reject(fn () => ContentImport::fields($export, ['en'], 'new'));
    $reject(fn () => ContentImport::fields($export, ['cs'], 'other'));
    $check(ContentImport::decode("\xEF\xBB\xBF" . $json) === $data &&
        ContentImport::decode("```json\n" . $json . "\n```") === $data, 'UTF-8 BOM or Markdown-wrapped JSON failed.');

    $legacy = array_replace($row, ['body' => "Původní text\n<script>alert(1)</script>"]);
    $legacyFields = ContentImport::fields(ContentImport::decode(ContentImport::export($legacy)), ['cs'], 'update', $legacy);
    $check($legacyFields['body'] === $legacy['body'], 'Legacy plain text must survive a round trip.');
    $renderer = new PageRenderer(dirname(__DIR__) . '/view');
    ob_start();
    $renderer->render($type, ['basePath' => '/shop/', 'language' => 'cs', 'content' => $legacy]);
    $public = ob_get_clean();
    $check(!str_contains($public, 'section=content-import') && !str_contains($public, '<script>alert(1)</script>'), 'Public view leaked transfer controls or executed imported HTML.');
    ob_start();
    $renderer->render($type, ['basePath' => '/shop/', 'language' => 'cs', 'content' => $row,
        'canEditContent' => true, 'contentEditMode' => true, 'editToken' => 'test-token']);
    $editor = ob_get_clean();
    $check(str_contains($editor, 'Export JSON') && str_contains($editor, 'Import JSON') &&
        str_contains($editor, '/shop/admin.php?section=content-import'), 'Document editor transfer controls are missing.');
}
$minimal = ['format' => ContentImport::FORMAT, 'type' => 'page', 'language' => 'cs', 'content' => ['title' => 'O nás', 'body' => 'Text stránky']];
$fields = ContentImport::fields(ContentImport::decode(json_encode($minimal)), ['cs'], 'new');
$check(ContentBody::decode($fields['body'])[0]['body'] === 'Text stránky', 'Plain body did not become editable text.');
foreach (['{', '[]', '{"content":[]}', str_repeat(' ', ContentImport::LIMIT + 1),
    str_replace(ContentImport::FORMAT, 'unknown', json_encode($minimal)),
    json_encode($minimal + ['sql' => 'DELETE FROM shop_content_revisions']),
    json_encode($minimal + ['document_key' => []]), json_encode($minimal + ['revision' => '3'])] as $bad) {
    $reject(fn () => ContentImport::decode($bad));
}
foreach ([['title' => []], ['title' => ''], ['title' => str_repeat('ž', 256)], ['slug' => 'blog'],
    ['slug' => '../../file'], ['published' => 'false'], ['visible_in_menu' => 1], ['menu_order' => -1],
    ['menu_order' => '1'], ['summary' => []], ['body' => []], ['sections' => []], ['extra' => 'field']] as $bad) {
    $input = $minimal;
    $input['content'] = array_replace($input['content'], $bad);
    $reject(fn () => ContentImport::fields(ContentImport::decode(json_encode($input)), ['cs'], 'new'));
}
foreach ([null, (object) [], ['text'], [['type' => 'script', 'heading' => '', 'body' => 'bad']],
    [['type' => 'image', 'heading' => '', 'body' => 'https://example.org/x.jpg']],
    [['type' => 'image', 'heading' => '', 'body' => 'images/../secret.php']],
    [['type' => 'text', 'heading' => '', 'body' => str_repeat('x', 300001)]],
    array_fill(0, 61, ['type' => 'text', 'heading' => '', 'body' => 'a'])] as $sections) {
    $input = $minimal;
    unset($input['content']['body']);
    $input['content']['sections'] = $sections;
    $reject(fn () => ContentImport::fields(ContentImport::decode(json_encode($input)), ['cs'], 'new'));
}
$minimal['type'] = 'post';
$minimal['content']['summary'] = str_repeat('č', 181);
$reject(fn () => ContentImport::fields(ContentImport::decode(json_encode($minimal)), ['cs'], 'new'));
$check(AdminActionPolicy::allows('content-import-preview') && AdminActionPolicy::allows('content-import-save'), 'Import actions are not authorized for admins.');

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$adminUrl = '/shop/admin.php'; $basePath = '/shop/'; $site = ['default_language' => 'cs'];
$csrf = 'test-token'; $importMode = 'new'; $importError = 'Chyba'; $importPreview = null; $exportUrl = '';
$importJson = '</textarea><script>alert(1)</script>';
ob_start(); require dirname(__DIR__) . '/view/admin/content-import.php'; $html = ob_get_clean();
$check(str_contains($html, '&lt;/textarea&gt;') && !str_contains($html, '<script>alert(1)</script>'), 'Failed input was not safely retained.');
$check(str_contains($html, 'multipart/form-data') && str_contains($html, 'name="csrf"') &&
    str_contains($html, 'value="content-import-preview"') && !str_contains($html, 'value="content-import-save"'), 'Upload or preview-first flow is broken.');
echo "Content JSON: page/article round trips, partial updates, conflicts, validation and private controls passed.\n";
