<?php
declare(strict_types=1);

use SimpleStore\Product\ProductMassUpdate;

// The shared admin entry point enforces authentication and CSRF validation.
$form = [];
$massStore = new ProductMassUpdate($db, (int) ($site['revision_limit'] ?? 50));
$massValues = ['quantity' => '', 'stock_status' => '', 'visibility' => ''];
$massPreview = null;
$massNotice = $_SESSION['product_mass_notice'] ?? null;
unset($_SESSION['product_mass_notice']);
if ($method === 'POST') {
    foreach ($massValues as $key => $value) {
        if (is_string($_POST[$key] ?? null)) $massValues[$key] = $_POST[$key];
    }
    try {
        if (($_POST['action'] ?? '') === 'products-mass-preview') {
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
