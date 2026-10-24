<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Content\ContentBody;
use SimpleStore\Content\ContentInlineEditor;
use SimpleStore\Content\ContentImport;
use SimpleStore\Media\MediaAttachment;
use SimpleStore\Product\ProductDetails;

$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call): void { try { $call(); } catch (InvalidArgumentException) { return; } throw new RuntimeException('Invalid cover was accepted.'); };
$row = ContentInlineEditor::starter('post', 'cs') + ['document_key' => str_repeat('a', 32), 'revision_number' => 3];
$blocks = ContentBody::decode($row['body']);
$check(ContentBody::cover($row['body']) === 'images/batoh.webp', 'Existing articles must keep their first-image cover.');
$cover = 'images/media/library/' . str_repeat('b', 32) . '/obrazek--' . str_repeat('c', 24) . '.webp';
$attached = MediaAttachment::document($row, [$cover], 'cover-image');
$check(ContentBody::decode($attached['body']) === $blocks && ContentBody::cover($attached['body']) === $cover, 'Selecting cover must not insert or change body blocks.');
$row = array_replace($row, $attached);
$form = ContentInlineEditor::fromRevision($row);
$form = ContentInlineEditor::change($form, 'section.remove', '', '', 3);
$saved = ContentInlineEditor::snapshot($form);
$check(ContentBody::cover($saved['body']) === $cover, 'Editing blocks lost independent cover.');
foreach ([null, '', $cover] as $setting) {
    $row['body'] = ContentBody::encode($blocks, $setting);
    $copy = ContentImport::fields(ContentImport::decode(ContentImport::export($row)), ['cs'], 'update', $row);
    $check($copy['body'] === $row['body'], 'Cover mode did not survive JSON roundtrip.');
    $patch = ContentImport::decode(ContentImport::export($row));
    $patch['content'] = ['body' => 'Nový text'];
    $copy = ContentImport::fields($patch, ['cs'], 'update', $row);
    $check(ContentBody::coverSetting($copy['body']) === $setting, 'Partial text import reset cover choice.');
}
$form = ContentInlineEditor::fromRevision($row);
$none = ContentInlineEditor::snapshot(ContentInlineEditor::change($form, 'set', 'cover_path', ''));
$auto = ContentInlineEditor::snapshot(ContentInlineEditor::change($form, 'set', 'cover_path', '@auto'));
$check(ContentBody::cover($none['body']) === '' && ContentBody::cover($auto['body']) === 'images/batoh.webp', 'Auto and no-image modes were conflated.');
$reject(fn () => MediaAttachment::document($row, [$cover, $cover], 'cover-image'));
foreach (['https://evil.example/a.jpg', 'images/../config/site.php', 'images/a.jpg.php', 'data:image/png;base64,aaaa'] as $bad) {
    $reject(fn () => ContentBody::encode($blocks, $bad));
    $reject(fn () => ContentInlineEditor::change($form, 'set', 'cover_path', $bad));
}
$check(ProductDetails::imagePath('images/old-gallery/cover.jpg') && !ProductDetails::imagePath('images/old-gallery/../cover.jpg'), 'Legacy subfolders need safe reusable paths.');
echo "Article covers: independent selection, body edits, auto/none, import and unsafe paths passed.\n";
