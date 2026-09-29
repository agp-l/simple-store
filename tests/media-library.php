<?php
declare(strict_types=1);

use SimpleStore\Content\ContentBody;
use SimpleStore\Content\ContentInlineEditor;
use SimpleStore\Media\MediaAttachment;
use SimpleStore\Media\MediaLibrary;
use SimpleStore\Media\MediaPath;
use SimpleStore\Product\ProductDetails;
use SimpleStore\Product\ProductInlineEditor;

require dirname(__DIR__) . '/src/bootstrap.php';

$key = str_repeat('a', 32);
$path = 'images/media/products/' . $key . '/boty-zepredu--' . str_repeat('b', 24) . '.webp';
if (!MediaPath::isManaged($path) || !ProductDetails::imagePath($path) ||
    MediaPath::variant($path, 'thumb') !== substr($path, 0, -5) . '-thumb.webp' ||
    !ProductDetails::imagePath(MediaPath::variant($path, 'thumb')) ||
    MediaPath::isManaged('images/media/products/' . $key . '/../attack.webp')) {
    throw new RuntimeException('Neplatné pravidlo pro adresy fotografií.');
}
foreach (['jpg', 'png'] as $extension) {
    $other = substr($path, 0, -4) . $extension;
    if (!MediaPath::isManaged($other) || !ProductDetails::imagePath($other) ||
        !MediaPath::isAsset(MediaPath::variant($other, 'card')) ||
        MediaPath::variant($other, 'thumb') !== substr($other, 0, -strlen($extension) - 1) . '-thumb.' . $extension ||
        MediaPath::label($other) !== 'boty-zepredu') {
        throw new RuntimeException('Neplatné adresy alternativních formátů.');
    }
}
try {
    MediaPath::directory('product', '../escape');
    throw new RuntimeException('Klíč složky dovolil průchod adresářem.');
} catch (InvalidArgumentException $expected) {}

$form = ProductInlineEditor::starter('cs', 'boty');
$product = $form + ['category' => 'boty', 'subcategory' => ''];
$product['details_json'] = json_encode(ProductDetails::fromForm($form), JSON_THROW_ON_ERROR);
$side = str_replace('zepredu', 'zboku', $path);
$back = str_replace('zepredu', 'zezadu', $path);
$attached = MediaAttachment::product($product, [$path, $side]);
if ($attached['image_path'] !== $path || !str_contains($attached['gallery'], 'zboku') ||
    $attached['section_body'][3] !== $path) {
    throw new RuntimeException('Hromadné nahrání nepřiřadilo snímky produktu.');
}
$firstGallery = MediaAttachment::product($product, [$path, $side], 'gallery-add');
if ($firstGallery['image_path'] !== $path || $firstGallery['gallery'] !== $side ||
    $firstGallery['section_body'][3] !== $path) {
    throw new RuntimeException('První galerie nového produktu ponechala ukázkový obrázek jako hlavní.');
}
$product['image_path'] = $path;
$product['details_json'] = json_encode(['gallery' => [$side],
    'sections' => ProductDetails::decode($product['details_json'])['sections'],
    'specifications' => [], 'options' => []], JSON_THROW_ON_ERROR);
$inGallery = MediaAttachment::product($product, [$back], 'gallery-add');
if ($inGallery['image_path'] !== $path || explode("\n", $inGallery['gallery']) !== [$side, $back]) {
    throw new RuntimeException('Nahrání do galerie změnilo hlavní fotografii.');
}
$replacedGallery = MediaAttachment::product($product, [$back, $side], 'gallery-set', 0);
if ($replacedGallery['image_path'] !== $path || $replacedGallery['gallery'] !== $back . "\n" . $side) {
    throw new RuntimeException('Výměna galerie nebo další snímky dávky selhaly.');
}
$replacedBlock = MediaAttachment::product($product, [$back], 'section-image', 3);
if ($replacedBlock['image_path'] !== $path || $replacedBlock['section_body'][3] !== $back ||
    $replacedBlock['gallery'] !== $side) {
    throw new RuntimeException('Výměna obrazového bloku změnila jinou část produktu.');
}
$insertedBlock = MediaAttachment::product($product, [$back], 'section-add-image', 2);
if ($insertedBlock['image_path'] !== $path || $insertedBlock['section_body'][3] !== $back ||
    $insertedBlock['section_type'][3] !== 'image') {
    throw new RuntimeException('Fotografie se nevložila na zvolené místo v popisu.');
}
$firstBlock = MediaAttachment::product($product, [$back], 'section-add-image', -1);
if ($firstBlock['section_body'][0] !== $back) {
    throw new RuntimeException('Fotografie se nevložila před první blok produktu.');
}
$uses = MediaAttachment::withUsage($product, 'product', [
    ['path' => $path], ['path' => $side], ['path' => $back], ['path' => 'images/batoh.webp'],
]);
if ($uses[0]['uses'] !== ['main'] || $uses[1]['uses'] !== ['gallery'] ||
    $uses[2]['uses'] !== [] || $uses[3]['uses'] !== ['section']) {
    throw new RuntimeException('Knihovna chybně označila použití snímků.');
}
$variantMain = $product;
$variantMain['image_path'] = MediaPath::variant($path, 'card');
if (MediaAttachment::withUsage($variantMain, 'product', [['path' => $path]])[0]['uses'] !== ['main']) {
    throw new RuntimeException('Knihovna nepoznala hlavní fotografii použitou jako zmenšeninu.');
}
try {
    MediaAttachment::capacity($product, 'product', 1, 'gallery-set', 9);
    throw new RuntimeException('Neexistující snímek galerie lze nahradit.');
} catch (InvalidArgumentException $expected) {}
$product['image_path'] = 'images/stara.webp';
$product['details_json'] = json_encode(['gallery' => [],
    'sections' => [], 'specifications' => [], 'options' => []], JSON_THROW_ON_ERROR);
$attached = MediaAttachment::product($product, [$path]);
if ($attached['gallery'] !== 'images/stara.webp') {
    throw new RuntimeException('Původní hlavní obrázek se ztratil.');
}
$product['image_path'] = 'images/stara.webp';
$product['details_json'] = json_encode(['gallery' => [$path],
    'sections' => [], 'specifications' => [], 'options' => []], JSON_THROW_ON_ERROR);
$attached = MediaAttachment::product($product, [$path]);
if ($attached['gallery'] !== 'images/stara.webp') {
    throw new RuntimeException('Přepnutí fotografie z galerie vytvořilo duplicitu.');
}
$product['details_json'] = json_encode(['gallery' => array_fill(0, 12, $path),
    'sections' => [], 'specifications' => [], 'options' => []], JSON_THROW_ON_ERROR);
try {
    MediaAttachment::capacity($product, 'product', 1);
    throw new RuntimeException('Plná galerie přijala další soubor.');
} catch (InvalidArgumentException $expected) {}
$fullGallery = [];
for ($i = 0; $i < 12; $i++) {
    $fullGallery[] = str_replace(str_repeat('b', 24), str_pad(dechex($i + 1), 24, '0', STR_PAD_LEFT), $path);
}
$product['details_json'] = json_encode(['gallery' => $fullGallery,
    'sections' => [], 'specifications' => [], 'options' => []], JSON_THROW_ON_ERROR);
$promoted = MediaAttachment::product($product, [$fullGallery[0]]);
if ($promoted['image_path'] !== $fullGallery[0] ||
    count(explode("\n", $promoted['gallery'])) !== 12 ||
    !str_contains($promoted['gallery'], 'images/stara.webp')) {
    throw new RuntimeException('Nelze povýšit snímek z plné galerie na hlavní.');
}

$document = ContentInlineEditor::starter('post', 'cs');
$document['revision_number'] = 1;
$first = str_replace('/products/', '/posts/', $path);
$snapshot = MediaAttachment::document($document, [$first]);
$blocks = ContentBody::decode($snapshot['body']);
if (count($blocks) !== 4 || $blocks[3]['body'] !== $first) {
    throw new RuntimeException('Ukázkový blok článku se nenahradil fotografií.');
}
$document['body'] = $snapshot['body'];
$second = str_replace('zepredu', 'zboku', $first);
$replaced = MediaAttachment::document($document, [$second], 'section-image', 3);
if (count(ContentBody::decode($replaced['body'])) !== 4 ||
    ContentBody::decode($replaced['body'])[3]['body'] !== $second) {
    throw new RuntimeException('Nahrání místo snímku článku přidalo nový blok.');
}
$inserted = MediaAttachment::document($document, [$second], 'section-add-image', 0);
if (ContentBody::decode($inserted['body'])[1]['body'] !== $second) {
    throw new RuntimeException('Snímek článku se nevložil za vybraný blok.');
}
$insertedFirst = MediaAttachment::document($document, [$second], 'section-add-image', -1);
if (ContentBody::decode($insertedFirst['body'])[0]['body'] !== $second) {
    throw new RuntimeException('Snímek článku se nevložil před první blok.');
}
$documentUses = MediaAttachment::withUsage($document, 'post', [
    ['path' => $first], ['path' => $second],
]);
if ($documentUses[0]['uses'] !== ['section'] || $documentUses[1]['uses'] !== []) {
    throw new RuntimeException('Knihovna chybně označila použití snímků článku.');
}

if (extension_loaded('gd') && function_exists('imagepng') && function_exists('imagejpeg') &&
    function_exists('imagecreatefrompng') && function_exists('imagecreatefromjpeg')) {
    $temp = sys_get_temp_dir() . '/simple-store-media-' . bin2hex(random_bytes(6));
    mkdir($temp, 0700);
    try {
        $library = new MediaLibrary($temp);
        foreach (['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG] as $inputExtension => $inputType) {
            $input = $temp . '/source.' . $inputExtension;
            $image = imagecreatetruecolor(1600, 900);
            if ($inputExtension === 'png') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            }
            imagefilledrectangle($image, 50, 50, 1550, 850, imagecolorallocate($image, 40, 120, 60));
            if ($inputExtension === 'png') imagepng($image, $input);
            else imagejpeg($image, $input);
            imagedestroy($image);
            $result = $library->storeFile('product', $key, $input, 'Přední strana.' . $inputExtension);
            $expectedType = function_exists('imagewebp') ? IMAGETYPE_WEBP : $inputType;
            foreach ([$result => 1600, MediaPath::variant($result, 'card') => 960,
                MediaPath::variant($result, 'thumb') => 240] as $file => $limit) {
                $size = getimagesize($temp . '/' . $file);
                if ($size === false || max($size[0], $size[1]) > $limit || $size[2] !== $expectedType) {
                    throw new RuntimeException('Zpracování fotografie vytvořilo chybný rozměr nebo formát.');
                }
            }
            if (count($library->files('product', $key)) !== 1) {
                throw new RuntimeException('Miniatury se zobrazují jako samostatné fotografie.');
            }
            if ($inputExtension === 'png' && !function_exists('imagewebp')) {
                $converted = imagecreatefrompng($temp . '/' . MediaPath::variant($result, 'thumb'));
                if (imagecolorsforindex($converted, imagecolorat($converted, 0, 0))['alpha'] !== 127) {
                    throw new RuntimeException('PNG přišlo o průhlednost.');
                }
                imagedestroy($converted);
            }
            $library->removeNew([$result]);
            if (is_file($temp . '/' . $result)) throw new RuntimeException('Vrácení uploadu nechalo soubory na disku.');
            @unlink($input);
        }
    } finally {
        foreach (glob($temp . '/source.*') ?: [] as $file) @unlink($file);
        foreach (glob($temp . '/images/media/products/' . $key . '/*') ?: [] as $file) @unlink($file);
        @rmdir($temp . '/images/media/products/' . $key);
        @rmdir($temp . '/images/media/products');
        @rmdir($temp . '/images/media');
        @rmdir($temp . '/images');
        @rmdir($temp);
    }
}

echo "Media library tests passed.\n";
