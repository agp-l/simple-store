<?php
declare(strict_types=1);

namespace SimpleStore\Rendering;

use InvalidArgumentException;

/** Pass explicit data to PHP views without embedding SQL or Twig in templates. */
final class PageRenderer
{
    private const PAGES = ['catalog', 'product-record', 'page', 'blog', 'post', 'cart',
        'shipping', 'payment', 'review', 'complete', 'not-found', 'unavailable'];

    public function __construct(private string $viewPath)
    {
    }

    public function render(string $page, array $data = [], int $status = 200): void
    {
        if (!in_array($page, self::PAGES, true)) {
            throw new InvalidArgumentException('Unknown view.');
        }

        http_response_code($status);
        $pageTitle = (string) ($data['title'] ?? 'Dobrodruzi.cz — vybavení na každou cestu');
        $pageDescription = (string) ($data['description'] ?? 'Batohy, stany, spacáky a vybavení na cesty ven.');
        $language = (string) ($data['language'] ?? 'cs');
        $basePath = (string) ($data['basePath'] ?? '/');
        $siteRoot = htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $primaryMenu = $data['primaryMenu'] ?? [];
        $utilityMenu = $data['utilityMenu'] ?? [];
        $footerMenu = $data['footerMenu'] ?? [];
        $footerTitle = $data['footerTitle'] ?? 'Informace';
        $manualPrimaryMenu = (bool) ($data['manualPrimaryMenu'] ?? false);
        $manualUtilityMenu = (bool) ($data['manualUtilityMenu'] ?? false);
        $manualFooterMenu = (bool) ($data['manualFooterMenu'] ?? false);
        $manualCategoryMenu = (bool) ($data['manualCategoryMenu'] ?? false);
        $categoryMenu = $data['categoryMenu'] ?? [];
        $categoryMenuRoot = $data['categoryMenuRoot'] ?? null;
        $currentCategory = $data['currentCategory'] ?? null;
        $canManageCatalog = (bool) ($data['canManageCatalog'] ?? false);
        $managingCatalog = (bool) ($data['managingCatalog'] ?? false);
        $catalogVisibility = (string) ($data['catalogVisibility'] ?? 'all');
        $managementCategories = $data['managementCategories'] ?? [];
        $productDeleted = (bool) ($data['productDeleted'] ?? false);
        $adminCreate = $data['adminCreate'] ?? null;
        $privatePage = (bool) ($data['privatePage'] ?? false);
        $canManageMenu = (bool) ($data['canManageMenu'] ?? false);
        $categoryAdminUrl = (string) ($data['categoryAdminUrl'] ?? '');
        $newSubcategoryUrl = (string) ($data['newSubcategoryUrl'] ?? '');
        $menuAdminUrl = (string) ($data['menuAdminUrl'] ?? '');
        $categoryTrail = $data['categoryTrail'] ?? [];
        $categoryLabels = $data['categoryLabels'] ?? [];
        $content = $data['content'] ?? null;
        $posts = $data['posts'] ?? [];
        $products = $data['products'] ?? [];
        $nextUrl = (string) ($data['nextUrl'] ?? '');
        $searchTerm = (string) ($data['searchTerm'] ?? '');
        $searchAction = (string) ($data['searchAction'] ?? $basePath . $language);
        $sortChoice = (string) ($data['sortChoice'] ?? 'default');
        $product = $data['product'] ?? null;
        $canEditProduct = (bool) ($data['canEditProduct'] ?? false);
        $editMode = (bool) ($data['editMode'] ?? false);
        $contentEditMode = (bool) ($data['contentEditMode'] ?? false);
        $canEditContent = (bool) ($data['canEditContent'] ?? false);
        $canManageContent = (bool) ($data['canManageContent'] ?? false);
        $adminCsrf = (string) ($data['adminCsrf'] ?? '');
        $draftPosts = $data['draftPosts'] ?? [];
        $draftNextUrl = (string) ($data['draftNextUrl'] ?? '');
        $contentHistory = $data['contentHistory'] ?? [];
        $editToken = (string) ($data['editToken'] ?? '');
        $editorCategories = $data['editorCategories'] ?? [];
        $productHistory = $data['productHistory'] ?? [];
        $backLink = (string) ($data['backLink'] ?? '');
        $setupNotice = (string) ($data['setupNotice'] ?? '');
        $debugError = (string) ($data['debugError'] ?? '');
        $showErrors = (bool) ($data['showErrors'] ?? true);
        $cartUrl = (string) ($data['cartUrl'] ?? $basePath . $language . '/kosik');
        $checkoutUrl = (string) ($data['checkoutUrl'] ?? $basePath . $language . '/pokladna');
        $cartCount = (int) ($data['cartCount'] ?? 0);
        $cartToken = (string) ($data['cartToken'] ?? '');
        $checkout = $data['checkout'] ?? ['items' => [], 'count' => 0, 'subtotal_czk' => 0,
            'issues' => [], 'can_continue' => false];
        $delivery = $data['delivery'] ?? [];
        $customerAddresses = $data['customerAddresses'] ?? [];
        $shippingOptions = $data['shippingOptions'] ?? [];
        $selectedShippingPrice = $data['selectedShippingPrice'] ?? null;
        $error = (string) ($data['error'] ?? '');
        $step = (string) ($data['step'] ?? '');
        $bankConfigured = (bool) ($data['bankConfigured'] ?? false);
        $shippingConfigured = (bool) ($data['shippingConfigured'] ?? false);
        $termsUrl = (string) ($data['termsUrl'] ?? '');
        $order = $data['order'] ?? [];
        $bankPayment = $data['bankPayment'] ?? [];
        $orderUrl = (string) ($data['orderUrl'] ?? '');
        $qrMarkup = (string) ($data['qrMarkup'] ?? '');
        $checkoutReady = (bool) ($data['checkoutReady'] ?? false);
        $testCheckout = (bool) ($data['testCheckout'] ?? false);
        $compactHeader = (bool) ($data['compactHeader'] ?? false);
        $skipTarget = (string) ($data['skipTarget'] ?? 'produkty');
        require $this->viewPath . '/layout.php';
    }

    /** Reuse the same card templates for the first page and additional batches. */
    public function cards(string $kind, array $items, array $data): string
    {
        if (!in_array($kind, ['product', 'post'], true)) {
            throw new InvalidArgumentException('Unknown card type.');
        }
        $basePath = (string) $data['basePath'];
        $language = (string) $data['language'];
        $siteRoot = htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $categoryLabels = $data['categoryLabels'] ?? [];
        $canManageContent = (bool) ($data['canManageContent'] ?? false);
        $managingCatalog = (bool) ($data['managingCatalog'] ?? false);
        $canManageCatalog = (bool) ($data['canManageCatalog'] ?? false);
        $cartToken = (string) ($data['cartToken'] ?? '');
        $cartUrl = (string) ($data['cartUrl'] ?? $basePath . $language . '/kosik');
        ob_start();
        try {
            foreach ($items as $item) {
                if ($kind === 'product') {
                    $product = $item;
                    require $this->viewPath . '/product-card.php';
                } else {
                    $post = $item;
                    require $this->viewPath . '/blog-card.php';
                }
            }
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }
}
