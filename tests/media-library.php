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
$attached = MediaAttachment::product($product, [$path, str_replace('zepredu', 'zboku', $path)]);
if ($attached['image_path'] !== $path || !str_contains($attached['gallery'], 'zboku') ||
    $attached['section_body'][3] !== $path) {
    throw new RuntimeException('Hromadné nahrání nepřiřadilo snímky produktu.');
}
$product['image_path'] = 'images/stara.webp';
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

$document = ContentInlineEditor::starter('post', 'cs');
$document['revision_number'] = 1;
$first = str_replace('/products/', '/posts/', $path);
$snapshot = MediaAttachment::document($document, [$first]);
$blocks = ContentBody::decode($snapshot['body']);
if (count($blocks) !== 4 || $blocks[3]['body'] !== $first) {
    throw new RuntimeException('Ukázkový blok článku se nenahradil fotografií.');
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
