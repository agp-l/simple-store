<?php
declare(strict_types=1);

use SimpleStore\Product\ProductMassUpdate;

// The shared admin entry point enforces authentication and CSRF validation.
$form = [];
$massStore = new ProductMassUpdate($db, (int) ($site['revision_limit'] ?? 50));
$massValues = ['quantity' => '', 'stock_status' => '', 'visibility' => ''];
$massPreview = null;
$variantBulkStore = new \SimpleStore\Product\ProductVariantBulkCreate($db, $site['default_language']);
$variantBulkQuantity = '10';
$variantBulkPreview = null;
$massNotice = $_SESSION['product_mass_notice'] ?? null;
unset($_SESSION['product_mass_notice']);
if ($method === 'POST') {
    foreach ($massValues as $key => $value) {
        if (is_string($_POST[$key] ?? null)) $massValues[$key] = $_POST[$key];
    }
    try {
        if (in_array($_POST['action'] ?? '', ['products-variants-preview', 'products-variants-apply'], true)) {
            if (is_string($_POST['variant_quantity'] ?? null)) $variantBulkQuantity = $_POST['variant_quantity'];
            if ($_POST['action'] === 'products-variants-preview') {
                unset($_SESSION['product_variant_bulk_preview']);
                $variantBulkPreview = $variantBulkStore->preview($_POST['variant_quantity'] ?? null);
                $variantBulkPreview['token'] = bin2hex(random_bytes(24));
                $_SESSION['product_variant_bulk_preview'] = $variantBulkPreview;
            } else {
                $pending = $_SESSION['product_variant_bulk_preview'] ?? null;
                if (!is_array($pending) || !is_string($_POST['preview_token'] ?? null) ||
                    !hash_equals($pending['token'], $_POST['preview_token'])) {
                    throw new InvalidArgumentException('Nejdřív zobraz nový náhled vytvoření variant.');
                }
                $variantBulkQuantity = (string) $pending['quantity'];
                if (($_POST['confirm_variants'] ?? '') !== '1') throw new InvalidArgumentException('Potvrď vytvoření variant a nahrazení společného skladu.');
                unset($_SESSION['product_variant_bulk_preview']);
                $created = $variantBulkStore->apply($pending);
                $_SESSION['product_mass_notice'] = 'Hotovo. Vytvořeno ' . $created['variants'] . ' variant u ' . $created['products'] . ' produktů, každá s ' . $pending['quantity'] . ' volnými kusy.';
                header('Location: ' . $adminUrl . '?section=settings&tab=products', true, 303);
                exit;
            }
        } elseif (($_POST['action'] ?? '') === 'products-mass-preview') {
            unset($_SESSION['product_mass_preview']);
            $massPreview = $massStore->preview($massValues);
            $massPreview['token'] = bin2hex(random_bytes(24));
            $_SESSION['product_mass_preview'] = $massPreview;
        } elseif (($_POST['action'] ?? '') === 'products-mass-apply') {
            $pending = $_SESSION['product_mass_preview'] ?? null;
            if (!is_array($pending) || !is_string($_POST['preview_token'] ?? null) ||
                !hash_equals($pending['token'], $_POST['preview_token'])) {
                throw new InvalidArgumentException('Nejdřív zobraz nový náhled hromadné změny.');
            }
            $massValues = $pending['choices'];
            unset($_SESSION['product_mass_preview']);
            $count = $massStore->apply($pending);
            $_SESSION['product_mass_notice'] = 'Hotovo. Počet změněných produktů: ' . $count . '.';
            header('Location: ' . $adminUrl . '?section=settings&tab=products', true, 303);
            exit;
        } else {
            throw new InvalidArgumentException('Neplatná akce hromadné změny.');
        }
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        $settingsError = $error->getMessage();
    } catch (Throwable $error) {
        http_response_code(503);
        $settingsError = 'Změna nebyla uložena. Zkus nový náhled; při opakování chyby zkontroluj databázi.';
        error_log('Product mass update failed: ' . $error->getMessage());
    }
}
$massCount = $massStore->count();
