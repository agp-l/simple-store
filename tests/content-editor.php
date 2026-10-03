<?php
declare(strict_types=1);

use SimpleStore\Content\ContentBody;
use SimpleStore\Content\ContentInlineEditor;
use SimpleStore\Rendering\PageRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

foreach (['page', 'post'] as $type) {
    $starter = ContentInlineEditor::starter($type, 'cs');
    if ($starter['published'] || $starter['visible_in_menu'] ||
        count(ContentBody::decode($starter['body'])) !== 4) {
        throw new RuntimeException('A new document must start private and include four editable blocks.');
    }
    $form = ContentInlineEditor::fromRevision($starter);
    $form = ContentInlineEditor::change($form, 'set', 'title', 'Příběh z hor');
    if ($form['slug'] !== 'pribeh-z-hor') {
        throw new RuntimeException('The starter slug did not follow the first title edit.');
    }
    $form = ContentInlineEditor::change($form, 'set', 'slug', 'moje-adresa');
    $form = ContentInlineEditor::change($form, 'set', 'title', 'Další příběh');
    if ($form['slug'] !== 'moje-adresa') {
        throw new RuntimeException('Changing the title overwrote a custom slug.');
    }
    $form = ContentInlineEditor::change($form, 'section.add', '', 'list', 0);
    $form = ContentInlineEditor::change($form, 'section.set', 'section_body', "Pěšky\nNa kole", 1);
    $form = ContentInlineEditor::change($form, 'section.move', '', 'down', 1);
    if ($form['sections'][2]['body'] !== "Pěšky\nNa kole") {
        throw new RuntimeException('Moving a content block lost its text.');
    }
    $form = ContentInlineEditor::change($form, 'section.remove', '', '', 2);
    $form = ContentInlineEditor::change($form, 'set', 'published', '1');
    if ($type === 'page') {
        $form = ContentInlineEditor::change($form, 'set', 'visible_in_menu', '1');
        $form = ContentInlineEditor::change($form, 'set', 'menu_order', '7');
    }
    $snapshot = ContentInlineEditor::snapshot($form);
    if (count(ContentBody::decode($snapshot['body'])) !== 4 || !$snapshot['published'] ||
        $snapshot['slug'] !== 'moje-adresa' || $snapshot['type'] !== $type ||
        ($type === 'page' && (!$snapshot['visible_in_menu'] || $snapshot['menu_order'] !== 7))) {
        throw new RuntimeException('Saving a small edit did not preserve the complete document snapshot.');
    }
    if (ContentInlineEditor::fromRevision($snapshot)['sections'] !== $form['sections']) {
        throw new RuntimeException('A saved revision cannot be edited again.');
    }
    foreach ([['section.remove', '', '', 500], ['section.add', '', 'unknown', null],
        ['set', 'published', 'sure', null]] as [$operation, $field, $value, $index]) {
        try {
            ContentInlineEditor::change($form, $operation, $field, $value, $index);
            throw new RuntimeException('Invalid content edit was accepted.');
        } catch (InvalidArgumentException $expected) {
            // An invalid edit must not change the saved snapshot.
        }
    }
    if ($type === 'post') {
        try {
            ContentInlineEditor::change($form, 'set', 'visible_in_menu', '1');
            throw new RuntimeException('A post cannot be added to the page links.');
        } catch (InvalidArgumentException $expected) {
        }
    }

    $revision = $snapshot + [
        'document_key' => str_repeat('a', 32), 'revision_number' => 2,
    ];
    $renderer = new PageRenderer(dirname(__DIR__) . '/view');
    $data = ['basePath' => '/shop/', 'language' => 'cs', 'content' => $revision,
        'backLink' => '/shop/cs/blog'];
    ob_start();
    $renderer->render($type, $data);
    $public = ob_get_clean();
    ob_start();
    $renderer->render($type, $data + [
        'canEditContent' => true, 'contentEditMode' => true,
        'contentHistory' => [['revision_number' => 1, 'active_document_key' => null,
            'saved_at' => '2026-09-28 12:00:00', 'title' => 'První verze']],
        'editToken' => 'test-token',
    ]);
    $editor = ob_get_clean();
    $editableSectionCount = count(array_filter(ContentBody::decode($snapshot['body']),
        static fn (array $section): bool => $section['type'] !== 'image'));
    $compactEditableBodies = preg_match_all('/<div class="cms-block-text"[^>]*><(?:p>|ul\b|div\b)/', $editor) ===
        $editableSectionCount && preg_match('/<div class="cms-block-text"[^>]*>(<p>.*?)<\/div>/s',
        $editor, $firstBody) === 1 && str_ends_with($firstBody[1], '</p>');
    if (str_contains($public, 'content-editor-config') || str_contains($public, 'test-token') ||
        str_contains($public, 'data-content-action') ||
        !$compactEditableBodies ||
        !str_contains($editor, 'data-edit-field="section_body"') ||
        !str_contains($editor, 'data-content-action="section-add"') ||
        !str_contains($editor, 'data-content-action="restore"') ||
        !str_contains($editor, 'test-token') ||
        !str_contains($editor, 'noindex, nofollow') ||
        ($type === 'post' && (!str_contains($editor, '/shop/cs/blog/moje-adresa') ||
            str_contains($editor, 'data-content-select="visible_in_menu"')))) {
        throw new RuntimeException('The public page leaked edit controls or the editor is incomplete.');
    }
}

$legacy = "Starý text\n\n<script>alert(1)</script>";
$blocks = ContentBody::decode($legacy);
if (count($blocks) !== 1 || $blocks[0]['body'] !== $legacy ||
    ContentBody::decode(ContentBody::encode($blocks)) !== $blocks) {
    throw new RuntimeException('Existing plain-text pages must survive a block edit without losing their content.');
}
$renderer = new PageRenderer(dirname(__DIR__) . '/view');
ob_start();
$renderer->render('page', ['basePath' => '/shop/', 'language' => 'cs',
    'content' => ['title' => 'Legacy', 'summary' => '', 'body' => $legacy, 'slug' => 'legacy']]);
$html = ob_get_clean();
if (!str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') ||
    str_contains($html, '<script>alert(1)</script>')) {
    throw new RuntimeException('A legacy page was rendered as unsafe HTML.');
}

$site = ['languages' => ['cs', 'en'], 'default_language' => 'cs'];
$screen = 'editor';
$basePath = '/shop/';
$adminUrl = '/shop/admin.php';
$csrf = 'test-token';
$documents = [['document_key' => str_repeat('a', 32), 'language' => 'cs',
    'type' => 'post', 'slug' => 'moje-adresa', 'title' => 'Můj článek',
    'revision_number' => 2, 'published' => 0]];
$translations = [str_repeat('a', 32) => ['cs']];
$filterLanguage = '';
$filterType = '';
$filterStatus = '';
$filterSearch = '';
$previousUrl = '';
$nextUrl = '/shop/admin.php?section=contents&offset=24';
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$admin = ob_get_clean();
if (!str_contains($admin, '/shop/cs/blog/moje-adresa?edit=1') ||
    !str_contains($admin, 'name="q"') ||
    !str_contains($admin, 'value="draft"') ||
    !str_contains($admin, 'section=contents&amp;offset=24') ||
    !str_contains($admin, 'value="create-translation"') ||
    !str_contains($admin, '<option value="en">en</option>') ||
    str_contains($admin, 'value="create-content"') ||
    str_contains($admin, '<textarea')) {
    throw new RuntimeException('The content index lost search, editor links, pagination or translation creation.');
}
echo "Inline content editor tests passed.\n";
