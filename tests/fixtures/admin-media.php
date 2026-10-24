<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$basePath = '/'; $adminUrl = '/admin.php'; $csrf = 'fixture-token';
$site['languages'] = ['cs', 'en']; $site['default_language'] = 'cs';
$fixture = $argv[1] ?? 'media';
if ($fixture === 'cover') {
    $row = \SimpleStore\Content\ContentInlineEditor::starter('post', 'cs') + ['document_key' => str_repeat('a', 32), 'revision_number' => 2, 'saved_at' => '2026-10-25 10:00:00'];
    $row['title'] = 'Podzimní výprava do hor'; $row['slug'] = 'podzimni-vyprava'; $row['summary'] = 'Cesty, na které se rádi vracíme.';
    (new \SimpleStore\Rendering\PageRenderer(dirname(__DIR__, 2) . '/view'))->render('post', ['basePath' => '/', 'language' => 'cs', 'content' => $row, 'canEditContent' => true, 'contentEditMode' => true, 'editToken' => $csrf]);
    exit;
}
if (in_array($fixture, ['media', 'product-folders', 'product-photos', 'product-open', 'product-search', 'product-empty', 'page-folders', 'post-folders'], true)) {
    $screen = 'media'; $mediaCollection = true; $mediaFolder = 'all'; $mediaSearch = ''; $mediaOffset = 0; $mediaNotice = ''; $mediaError = '';
    $mediaFolders = [str_repeat('a', 32) => 'Podzimní výpravy', str_repeat('b', 32) => 'Podzimní výpravy / Krkonoše', str_repeat('c', 32) => 'Nové produkty'];
    $mediaState = ['folders' => []];
    foreach ($mediaFolders as $id => $name) $mediaState['folders'][$id] = ['name' => $name, 'parent' => ''];
    $files = [];
    foreach (['batoh.webp', 'kresadlo.webp', 'spacak.webp', 'batoh.webp', 'batoh.webp', 'kresadlo.webp'] as $i => $name) {
        $files[] = ['path' => 'images/' . $name, 'thumb' => 'images/' . $name, 'card' => 'images/' . $name, 'label' => ['Vybavení na víkendovou výpravu', 'Pohorky do hor', 'Spací pytel', 'Kolekce podzim', 'Novinky do batohu', 'Na cestě'][$i], 'folder' => $i % 2 === 0 ? str_repeat('a', 32) : '', 'bytes' => 185000, 'created' => 1792880000 - $i, 'group' => 'products'];
    }
    $mediaOwners = [];
    if ($fixture !== 'media') {
        $mediaFolder = $fixture === 'page-folders' ? 'pages' : ($fixture === 'post-folders' ? 'posts' : 'products');
        foreach ($files as $i => &$file) {
            $key = str_repeat(dechex(intdiv($i, 2) + 1), 32);
            $file['path'] = 'images/media/' . $mediaFolder . '/' . $key . '/obrazek--' . str_pad(dechex($i + 1), 24, '0', STR_PAD_LEFT) . '.webp';
            $file['group'] = $mediaFolder;
            if ($i < 4) $mediaOwners[$mediaFolder . ':' . $key] = ['title' => ['Batoh na víkendovou výpravu', 'Lehký stan do hor'][intdiv($i, 2)], 'image_path' => $file['path'], 'key' => $key, 'type' => \SimpleStore\Media\MediaOwners::TYPES[$mediaFolder], 'slug' => 'vybaveni-' . $key, 'language' => 'cs', 'published' => $i < 2, 'revision_number' => 3];
        }
        unset($file);
    }
    $mediaOptions = \SimpleStore\Media\MediaBrowse::options(['folder' => $mediaFolder,
        'owner' => in_array($fixture, ['product-open', 'product-empty'], true) ? str_repeat('1', 32) : '',
        'view' => $fixture === 'product-photos' ? 'photos' : ($fixture === 'media' ? 'photos' : 'folders'),
        'q' => $fixture === 'product-search' ? 'STAN' : ($fixture === 'product-empty' ? 'Nenalezeno' : '')]);
    $mediaSearch = $mediaOptions['q'];
    $mediaListing = \SimpleStore\Media\MediaBrowse::listing($files, $mediaState, $mediaOwners, $mediaOptions);

} else {
    $screen = 'editor'; $filterType = $fixture === 'posts' ? 'post' : 'page'; $filterLanguage = ''; $filterStatus = ''; $filterSearch = ''; $previousUrl = ''; $nextUrl = ''; $translations = [];
    $documents = [];
    foreach (['Jak se připravit na první výpravu', 'Náš příběh a cesta do hor', 'Vše o doručení a platbách'] as $i => $title) {
        $documents[] = ['document_key' => str_repeat(dechex($i + 1), 32), 'language' => 'cs', 'type' => $filterType, 'slug' => 'pribeh-' . $i, 'title' => $title, 'revision_number' => 3, 'published' => $i !== 1, 'visible_in_menu' => $i === 0, 'summary' => 'Praktické rady, osobní zkušenosti a inspirace pro další cestu. Vše, co potřebujete vědět, přehledně na jednom místě.', 'body' => \SimpleStore\Content\ContentBody::encode([['type' => 'image', 'heading' => '', 'body' => 'images/batoh.webp']]), 'saved_at' => '2026-10-25 10:00:00'];
    }
}
require dirname(__DIR__, 2) . '/view/admin/layout.php';
