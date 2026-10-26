<?php
declare(strict_types=1);

use SimpleStore\Product\ProductMassUpdate;

// The shared admin entry point enforces authentication and CSRF validation.
$form = [];
$massStore = new ProductMassUpdate($db, (int) ($site['revision_limit'] ?? 50));
$massValues = ['quantity' => '', 'stock_status' => '', 'visibility' => ''];
$massPreview = null;
$partnerAssignment = new \SimpleStore\Product\ProductPartnerAssignment($db, $site['default_language']);
$partnerAssignmentReady = $partnerAssignment->installed();
$partnerAssignmentPlans = [];
$variantBulkStore = new \SimpleStore\Product\ProductVariantBulkCreate($db, $site['default_language']);
$variantBulkQuantity = '10';
$variantBulkPreview = null;
$saleBulkStore = new \SimpleStore\Product\ProductSalesMassUpdate($db, $site['default_language']);
$saleBulkReady = $saleBulkStore->installed();
$saleBulkMode = is_string($_POST['sale_mode'] ?? null) ? $_POST['sale_mode'] : '';
$saleBulkPreview = null;
$massNotice = $_SESSION['product_mass_notice'] ?? null;
unset($_SESSION['product_mass_notice']);
if ($method === 'GET') {
    $cancel = $_GET['cancel_preview'] ?? '';
    if (in_array($cancel, ['mass', 'sale', 'variants'], true)) {
        unset($_SESSION[['mass' => 'product_mass_preview', 'sale' => 'product_sale_bulk_preview',
            'variants' => 'product_variant_bulk_preview'][$cancel]]);
    }
}
if ($method === 'POST') {
    foreach ($massValues as $key => $value) {
        if (is_string($_POST[$key] ?? null)) $massValues[$key] = $_POST[$key];
    }
    try {
        if (($_POST['action'] ?? '') === 'products-partners-assign') {
            if (!is_string($_POST['assignment_scope'] ?? null) || !is_string($_POST['assignment_token'] ?? null)) {
                throw new InvalidArgumentException('Obnov přehled přiřazení prodávajících.');
            }
            $result = $partnerAssignment->apply($_POST['assignment_scope'], $_POST['assignment_token'], $csrf);
            $_SESSION['product_mass_notice'] = 'Přiřazení dokončeno. Změněno: ' . $result['change'] .
                ', ponecháno: ' . ($result['same'] + $result['kept']) . ', bez odkazu: ' . $result['no_links'] .
                ', bez shody webu: ' . $result['no_match'] . ', shodná priorita: ' . $result['conflict'] .
                ', neúplné pokrytí variant: ' . $result['incomplete'] .
                '. Prodejní ceny a způsob prodeje se nezměnily. Nákupní ceny u změněných prodávajících je potřeba znovu načíst nebo doplnit.';
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=settings&tab=products#product-partner-assignment'), true, 303);
            exit;
        } elseif (in_array($_POST['action'] ?? '', ['products-sale-preview', 'products-sale-apply'], true)) {
            if ($_POST['action'] === 'products-sale-preview') {
                unset($_SESSION['product_sale_bulk_preview']);
                $saleBulkPreview = $saleBulkStore->preview($saleBulkMode);
                $saleBulkPreview['token'] = bin2hex(random_bytes(24));
                $_SESSION['product_sale_bulk_preview'] = $saleBulkPreview;
            } else {
                $pending = $_SESSION['product_sale_bulk_preview'] ?? null;
                if (!is_array($pending) || !is_string($_POST['preview_token'] ?? null) ||
                    !hash_equals($pending['token'], $_POST['preview_token'])) {
                    throw new InvalidArgumentException('Nejdřív zobraz nový náhled přepnutí způsobu prodeje.');
                }
                $saleBulkMode = $pending['mode'];
                unset($_SESSION['product_sale_bulk_preview']);
                $changed = $saleBulkStore->apply($pending);
                $_SESSION['product_mass_notice'] = 'Hotovo. Všechny produkty mají způsob prodeje „' .
                    ($saleBulkMode === 'assisted' ? 'Asistovaný nákup' : 'Prodáváme my') . '“. Změněno: ' . $changed . '.';
                if ($pending['issues'] !== []) $_SESSION['product_mass_notice'] .= ' Doplnění partnerských údajů nebo cen vyžaduje ' . count($pending['issues']) . ' produktů; do té doby u nich není asistovaný nákup možný.';
                header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=settings&tab=products#bulk-product-sales'), true, 303);
                exit;
            }
        } elseif (in_array($_POST['action'] ?? '', ['products-variants-preview', 'products-variants-apply'], true)) {
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
                $variantBulkPreview = $pending;
                if (($_POST['confirm_variants'] ?? '') !== '1') throw new InvalidArgumentException('Potvrď vytvoření variant a nahrazení společného skladu.');
                $variantBulkPreview = null;
                unset($_SESSION['product_variant_bulk_preview']);
                $created = $variantBulkStore->apply($pending);
                $_SESSION['product_mass_notice'] = 'Hotovo. Vytvořeno ' . $created['variants'] . ' variant u ' . $created['products'] . ' produktů, každá s ' . $pending['quantity'] . ' volnými kusy.';
                header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=settings&tab=products'), true, 303);
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
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=settings&tab=products'), true, 303);
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
$saleBulkCounts = $saleBulkStore->counts();
if ($partnerAssignmentReady) {
    try { $partnerAssignmentPlans = $partnerAssignment->previews(); }
    catch (Throwable $error) {
        $partnerAssignmentReady = false;
        $settingsError = 'Přehled přiřazení se nepodařilo načíst. Obnov stránku; při opakování chyby zkontroluj databázi.';
        error_log('Partner assignment preview failed: ' . $error->getMessage());
    }
}
