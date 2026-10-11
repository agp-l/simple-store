<?php
declare(strict_types=1);

use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductVariantRepository;
use SimpleStore\Product\ProductVariants;

// Authentication and CSRF verification have already run in admin.php.
$key = $_POST['key'] ?? '';
$language = $_POST['language'] ?? '';
if (!is_string($key) || !is_string($language) || !in_array($language, $site['languages'], true) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) throw new InvalidArgumentException('Neplatný produkt.');
$product = (new ProductRepository($db, $site['languages']))->current($key, $language);
if ($product === null) throw new InvalidArgumentException('Produkt neexistuje.');
$store = new ProductVariantRepository($db);
try {
    $snapshot = $store->snapshot($key);
    $snapshot['expected'] = is_string($_POST['expected'] ?? null) ? $_POST['expected'] : '';
    $operation = $_POST['operation'] ?? '';
    if ($operation === 'merge-preview') {
        $slug = $_POST['source_slug'] ?? '';
        $groups = $_POST['merge_groups'] ?? '';
        if (!is_string($slug) || !is_string($groups)) throw new InvalidArgumentException('Neplatné zadání převodu.');
        $_POST['merge_preview'] = (new \SimpleStore\Product\ProductVariantMerge($db))->preview($key, trim($slug), $language, $groups, $snapshot['expected']);
        $_SESSION['variant_form'] = $_POST;
        $_SESSION['variant_error'] = ['key' => $key, 'message' => ''];
    } elseif ($operation === 'merge') {
        if (($_POST['confirm'] ?? '') !== '1') throw new InvalidArgumentException('Potvrď převod produktu.');
        $json = $_POST['merge_plan'] ?? '';
        if (!is_string($json) || strlen($json) > 16000) throw new InvalidArgumentException('Neplatný náhled.');
        try { $plan = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { throw new InvalidArgumentException('Neplatný náhled.'); }
        if (!is_array($plan)) throw new InvalidArgumentException('Neplatný náhled.');
        (new \SimpleStore\Product\ProductVariantMerge($db))->apply($key, $plan);
        unset($_SESSION['variant_error'], $_SESSION['variant_form']);
    } elseif ($operation === 'generate') {
        if (($_POST['confirm'] ?? '') !== '1') throw new InvalidArgumentException('Potvrď zapnutí skladu variant.');
        $text = $_POST['groups'] ?? '';
        if (!is_string($text) || strlen($text) > 16000) throw new InvalidArgumentException('Neplatné vlastnosti.');
        $generated = ProductVariants::generate($text);
        $existing = array_map(static fn (array $v): string => json_encode($v['choices'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $snapshot['items']);
        foreach ($generated as $variant) {
            if (!in_array(json_encode($variant['choices'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $existing, true)) $snapshot['items'][] = $variant;
        }
        $snapshot['enabled'] = true;
    } elseif (in_array($operation, ['row', 'batch'], true)) {
        $changes = [$_POST];
        if ($operation === 'batch') {
            $json = $_POST['rows_json'] ?? '';
            if (!is_string($json) || strlen($json) > 1048576) throw new InvalidArgumentException('Příliš velká změna variant.');
            try { $changes = json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
            catch (JsonException $error) { throw new InvalidArgumentException('Neplatné údaje variant.'); }
            if (!is_array($changes)) throw new InvalidArgumentException('Neplatné údaje variant.');
        }
        $snapshot = \SimpleStore\Product\ProductVariantForm::apply($snapshot, $changes);
    } elseif ($operation === 'mode') {
        if (($_POST['confirm'] ?? '') !== '1') throw new InvalidArgumentException('Potvrď změnu skladu.');
        $snapshot['enabled'] = ($_POST['enabled'] ?? '') === '1';
    } else throw new InvalidArgumentException('Neplatná operace.');
    if (!in_array($operation, ['merge', 'merge-preview'], true)) {
        $store->save($key, $snapshot);
        unset($_SESSION['variant_error'], $_SESSION['variant_form']);
    }
} catch (InvalidArgumentException $error) {
    $_SESSION['variant_error'] = ['key' => $key, 'message' => $error->getMessage()];
    $_SESSION['variant_form'] = $_POST;
}
header('Location: ' . $basePath . $language . '/produkt/' . rawurlencode($product['slug']) . '?edit=1#product-variants', true, 303);
exit;
