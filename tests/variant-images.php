<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Product\VariantImages;

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$base = 'images/base.webp';
$blueSmall = ['choices'=>['Barva'=>'Modrá','Velikost'=>'S'],'enabled'=>true,'image_path'=>''];
$blueLarge = ['choices'=>['Barva'=>'Modrá','Velikost'=>'L'],'enabled'=>true,'image_path'=>'images/blue.webp'];
$red = ['choices'=>['Barva'=>'Červená','Velikost'=>'S'],'enabled'=>true,'image_path'=>'images/red.webp'];
$rows = [$blueSmall, $red, $blueLarge];
$check(VariantImages::resolve($blueSmall, $rows, $base) === 'images/blue.webp', 'Sizes must share their color photo.');
$check(VariantImages::resolve($red, $rows, $base) === 'images/red.webp', 'Explicit combination photo must win.');
$check(VariantImages::resolve(array_replace($blueSmall, ['image_path'=>'images/detail.webp']), $rows, $base) === 'images/detail.webp', 'Explicit detail must not be replaced by another size.');
$rows[2]['enabled'] = false;
$check(VariantImages::resolve($blueSmall, $rows, $base) === $base, 'Disabled or differently colored variants must not supply the photo.');
$plain = ['choices'=>['Velikost'=>'S'],'enabled'=>true,'image_path'=>''];
$check(VariantImages::resolve($plain, [$plain, array_replace($plain, ['image_path'=>'images/other.webp'])], $base) === $base, 'Do not infer color from arbitrary attributes.');
$check(VariantImages::color(['Barva'=>'Modrá']) === ['name'=>'Barva','value'=>'Modrá'], 'Preserve exact identity.');
$check(VariantImages::color(['COLOUR'=>'Blue']) === ['name'=>'COLOUR','value'=>'Blue'], 'Recognize translated color attributes.');
$check(VariantImages::color(['Color'=>'Blue']) !== null && VariantImages::color(['Velikost'=>'S']) === null, 'Color recognition failed.');
echo "Variant color image tests passed.\n";

$previews = VariantImages::previews([$blueSmall, $blueLarge, $red, array_replace($red, ['enabled'=>false, 'image_path'=>'images/disabled.webp'])], $base);
$check(count($previews) === 3 && $previews[0]['image'] === 'images/blue.webp', 'Shared-stock previews lost enabled color photos.');
$check(array_keys($previews[0]) === ['choices','image'], 'Preview metadata leaked inventory or private variant fields.');
$check(VariantImages::previews([], $base) === [], 'Products without prepared variants must remain unchanged.');
