<?php
declare(strict_types=1);

namespace SimpleStore\Rendering;

use InvalidArgumentException;

/** Pass explicit data to PHP views without embedding SQL or Twig in templates. */
final class PageRenderer
{
    private const PAGES = ['catalog', 'product-record', 'page', 'blog', 'post', 'not-found', 'unavailable'];

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
        $manualPrimaryMenu = (bool) ($data['manualPrimaryMenu'] ?? false);
        $manualUtilityMenu = (bool) ($data['manualUtilityMenu'] ?? false);
        $manualFooterMenu = (bool) ($data['manualFooterMenu'] ?? false);
        $manualCategoryMenu = (bool) ($data['manualCategoryMenu'] ?? false);
        $categoryMenu = $data['categoryMenu'] ?? [];
        $categoryMenuRoot = $data['categoryMenuRoot'] ?? null;
        $currentCategory = $data['currentCategory'] ?? null;
        $canManageCatalog = (bool) ($data['canManageCatalog'] ?? false);
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
        $contentHistory = $data['contentHistory'] ?? [];
        $editToken = (string) ($data['editToken'] ?? '');
        $editorCategories = $data['editorCategories'] ?? [];
        $productHistory = $data['productHistory'] ?? [];
        $backLink = (string) ($data['backLink'] ?? '');
        $setupNotice = (string) ($data['setupNotice'] ?? '');
        $debugError = (string) ($data['debugError'] ?? '');
        $showErrors = (bool) ($data['showErrors'] ?? true);
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
