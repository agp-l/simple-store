<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Category\CategoryPath;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Navigation\Slugger;
use RuntimeException;
use Throwable;

/** Store each product edit as a new complete snapshot. */
final class ProductRepository
{
    public const SUPPLIER_FILTERS = ['all' => 'Všechny produkty', 'no_partner' => 'Bez prodávajícího',
        'no_links' => 'Bez dodavatelských odkazů', 'either' => 'Chybí prodávající nebo odkaz', 'both' => 'Chybí prodávající i odkazy'];
    private const STOCK = ['in_stock', 'on_order', 'out_of_stock'];

    public function __construct(
        private MeekroDB $db,
        private array $languages = ['cs'],
        private ?CategoryRepository $categories = null,
        private int $revisionLimit = 50,
        private ?ProductStockRepository $stock = null
    )
    {
        if ($revisionLimit < 1) {
            throw new InvalidArgumentException('The revision limit must be positive.');
        }
    }

    /** Fetch one SQL-filtered slice; the extra row tells the UI whether more exists. */
    public function publishedPage(
        string $language,
        ?string $category = null,
        string $search = '',
        string $sort = 'default',
        int $offset = 0,
        int $limit = 12,
        string $seed = '',
        array $tips = []
    ): array
    {
        if ($offset < 0 || $offset > 100000 || $limit < 1 || $limit > 48) {
            throw new InvalidArgumentException('Invalid catalog page.');
        }
        $where = 'language=%s AND published=1 AND active_product_key IS NOT NULL';
        $values = [$language];
        if ($category !== null) {
            [$categoryWhere, $categoryValues] = self::categoryFilter($category);
            $where .= ' AND ' . $categoryWhere;
            array_push($values, ...$categoryValues);
        }
        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND (LOCATE(%s, name)>0 OR LOCATE(%s, brand)>0 OR LOCATE(%s, summary)>0)';
            array_push($values, $search, $search, $search);
        }
        $order = match ($sort) {
            'price-asc' => 'price_czk ASC, product_key',
            'price-desc' => 'price_czk DESC, product_key',
            'name' => 'name ASC, product_key',
            default => 'id DESC',
        };
        if (in_array($sort, ['price-asc', 'price-desc'], true) && (new ProductVariantRepository($this->db))->installed()) {
            $order = 'COALESCE((SELECT MIN(COALESCE(v.price_czk, shop_product_revisions.price_czk))
                FROM shop_product_variants v JOIN shop_product_variant_settings s ON s.product_key=v.product_key
                WHERE v.product_key=shop_product_revisions.product_key AND s.enabled=1 AND v.enabled=1), price_czk)'
                . ($sort === 'price-desc' ? ' DESC' : ' ASC') . ', product_key';
        }
        // Recommendations affect discovery only; explicit sorts always win.
        if ($sort === 'random') {
            $order = 'SHA2(CONCAT(product_key, %s), 256), product_key';
            if ($tips !== []) {
                $tipOrder = 'FIELD(product_key, ' . implode(',', array_fill(0, count($tips), '%s')) . ')';
                $order = '(' . $tipOrder . '=0), ' . $tipOrder . ', ' . $order;
                array_push($values, ...$tips, ...$tips);
            }
            $values[] = $seed;
        }
        array_push($values, $limit + 1, $offset);
        $rows = $this->db->query(
            'SELECT product_key, slug, name, brand, summary, details_json, category, subcategory,
                    price_czk, image_path, sizes, stock_status
             FROM shop_product_revisions WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT %i OFFSET %i',
            ...$values
        );
        $hasMore = count($rows) > $limit;
        return ['items' => $this->stock?->decorate(array_slice($rows, 0, $limit)) ?? array_slice($rows, 0, $limit),
            'nextOffset' => $hasMore ? $offset + $limit : null];
    }

    /** Authenticated product browser, including drafts; never use on a public route. */
    public function managementPage(
        string $language,
        ?string $categoryPath = null,
        string $search = '',
        string $visibility = 'all',
        int $offset = 0,
        int $limit = 12,
        string $supplierState = 'all',
        int $partnerId = 0
    ): array
    {
        if (!in_array($language, $this->languages, true) ||
            !in_array($visibility, ['all', 'draft', 'published'], true) || !isset(self::SUPPLIER_FILTERS[$supplierState]) ||
            $offset < 0 || $offset > 100000 || $limit < 1 || $limit > 48 || strlen($search) > 200 || $partnerId < 0) {
            throw new InvalidArgumentException('Invalid product management page.');
        }
        $where = 'language=%s AND active_product_key IS NOT NULL';
        $values = [$language];
        if ($visibility !== 'all') {
            $where .= $visibility === 'published' ? ' AND published=1' : ' AND published=0';
        }
        if ($categoryPath !== null) {
            [$categoryWhere, $categoryValues] = self::categoryFilter($categoryPath);
            $where .= ' AND ' . $categoryWhere;
            array_push($values, ...$categoryValues);
        }
        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND (LOCATE(%s, name)>0 OR LOCATE(%s, brand)>0 OR LOCATE(%s, summary)>0)';
            array_push($values, $search, $search, $search);
        }
        $supplierTablesReady = (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s)',
            'shop_product_sales', 'shop_purchase_partners', 'shop_product_supplier_links') === 3;
        $sellerQuery = 'SELECT p.id FROM shop_product_sales s JOIN shop_purchase_partners p ON p.id=s.partner_id WHERE s.product_key=r.product_key';
        $linksQuery = 'SELECT 1 FROM shop_product_supplier_links l WHERE l.product_key=r.product_key';
        $variantLinksReady = (new ProductVariantSupplierRepository($this->db))->installed();
        $variantLinksQuery = 'SELECT 1 FROM shop_product_variant_supplier_links l WHERE l.product_key=r.product_key AND l.removed=0';
        if ($partnerId > 0) {
            $partner = (new PurchasePartnerRepository($this->db))->find($partnerId);
            if ($partner === null) throw new InvalidArgumentException('Vybraný partner již neexistuje.');
            $keys = array_keys((new PurchasePartnerUsage($this->db))->products([$partner])[$partnerId]);
            // Apply before LIMIT/OFFSET, including sources attached only to one variant.
            $where .= $keys === [] ? ' AND 1=0' : ' AND r.product_key IN (' . implode(',', array_fill(0, count($keys), '%s')) . ')';
            array_push($values, ...$keys);
        }
        if ($supplierTablesReady && $supplierState !== 'all') {
            $withoutSeller = 'NOT EXISTS (' . $sellerQuery . ')';
            $withoutLinks = 'NOT EXISTS (' . $linksQuery . ')';
            if ($variantLinksReady) $withoutLinks .= ' AND NOT EXISTS (' . $variantLinksQuery . ')';
            $where .= ' AND (' . ['no_partner' => $withoutSeller, 'no_links' => $withoutLinks,
                'either' => $withoutSeller . ' OR ' . $withoutLinks, 'both' => $withoutSeller . ' AND ' . $withoutLinks][$supplierState] . ')';
        }
        $supplierColumns = $supplierTablesReady
            ? ', (' . $sellerQuery . ') AS admin_partner_id, (' . str_replace('SELECT p.id', 'SELECT p.name', $sellerQuery) . ') AS admin_partner_name,
                ((SELECT COUNT(*) FROM shop_product_supplier_links l WHERE l.product_key=r.product_key)' .
                ($variantLinksReady ? ' + (SELECT COUNT(*) FROM shop_product_variant_supplier_links l WHERE l.product_key=r.product_key AND l.removed=0)' : '') . ') AS admin_supplier_count'
            : ', NULL AS admin_partner_id, NULL AS admin_partner_name, 0 AS admin_supplier_count';
        array_push($values, $limit + 1, $offset);
        $rows = $this->db->query(
            'SELECT product_key, language, slug, name, brand, summary, details_json,
                    category, subcategory, price_czk, image_path, sizes, stock_status,
                    published, revision_number, saved_at ' . $supplierColumns . '
             FROM shop_product_revisions r WHERE ' . $where . ' ORDER BY r.id DESC LIMIT %i OFFSET %i',
            ...$values
        );
        return ['items' => $this->stock?->decorate(array_slice($rows, 0, $limit)) ?? array_slice($rows, 0, $limit),
            'nextOffset' => count($rows) > $limit ? $offset + $limit : null];
    }

    /** Product paths include descendants and legacy rows stored before nested categories. */
    private static function categoryFilter(string $category): array
    {
        if (!CategoryPath::valid($category)) {
            throw new InvalidArgumentException('Invalid category path.');
        }
        [$root, $child] = CategoryPath::forStorage($category);
        $parts = [];
        $values = [];
        if ($child === '') {
            $parts[] = 'category=%s';
            $values[] = $root;
        } else {
            $parts[] = '(category=%s AND (subcategory=%s OR subcategory LIKE %s))';
            array_push($values, $root, $child, $child . '/%');
        }
        foreach (CategoryPath::legacyProductPaths($category) as [$oldRoot, $oldChild]) {
            $parts[] = $oldChild === null ? 'category=%s' : '(category=%s AND subcategory=%s)';
            $values[] = $oldRoot;
            if ($oldChild !== null) $values[] = $oldChild;
        }
        return ['(' . implode(' OR ', $parts) . ')', $values];
    }

    public function findPublished(string $slug, string $language): ?array
    {
        $row = $this->db->queryFirstRow(
            'SELECT * FROM shop_product_revisions WHERE slug=%s AND language=%s AND published=1
             AND active_product_key IS NOT NULL LIMIT 1', $slug, $language
        );
        return $this->withStock($row);
    }

    /** Resolve cart entries by permanent identity; browser supplied prices are ignored. */
    public function findPublishedByKey(string $key, string $language): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
            !in_array($language, $this->languages, true)) {
            throw new InvalidArgumentException('Neplatný produkt v košíku.');
        }
        $row = $this->db->queryFirstRow(
            'SELECT * FROM shop_product_revisions WHERE product_key=%s AND language=%s
             AND active_product_key IS NOT NULL AND published=1 LIMIT 1', $key, $language
        );
        return $this->withStock($row);
    }

    /** This lookup is for authenticated preview and never belongs on a public route. */
    public function findCurrentBySlug(string $slug, string $language): ?array
    {
        $row = $this->db->queryFirstRow(
            'SELECT * FROM shop_product_revisions WHERE active_slug=%s AND language=%s
             AND active_product_key IS NOT NULL LIMIT 1', $slug, $language
        );
        return $this->withStock($row);
    }

    public function current(string $key, string $language): ?array
    {
        $row = $this->db->queryFirstRow(
            'SELECT * FROM shop_product_revisions WHERE product_key=%s AND language=%s
             AND active_product_key IS NOT NULL LIMIT 1', $key, $language
        );
        return $this->withStock($row);
    }

    private function withStock(?array $row): ?array
    {
        return $row === null || $this->stock === null ? $row : $this->stock->decorate([$row])[0];
    }

    public function history(string $key, string $language): array
    {
        return $this->db->query(
            'SELECT * FROM shop_product_revisions WHERE product_key=%s AND language=%s
             ORDER BY revision_number DESC', $key, $language
        );
    }

    /** Preview the history without loading every past description and gallery. */
    public function historySummary(string $key, string $language): array
    {
        return $this->db->query(
            'SELECT revision_number, name, saved_at, active_product_key
             FROM shop_product_revisions WHERE product_key=%s AND language=%s
             ORDER BY revision_number DESC', $key, $language
        );
    }

    public function revision(string $key, string $language, int $number): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM shop_product_revisions WHERE product_key=%s AND language=%s
             AND revision_number=%i LIMIT 1', $key, $language, $number
        );
    }

    public function detailsColumnExists(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
            'shop_product_revisions', 'details_json'
        ) > 0;
    }

    /** Delete one language of a product and its revisions; media files are left untouched. */
    public function deleteProduct(string $key, string $language, int $expectedRevision): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
            !in_array($language, $this->languages, true) || $expectedRevision < 1) {
            throw new InvalidArgumentException('Neplatný produkt nebo číslo revize.');
        }

        $this->db->startTransaction();
        try {
            if ((new ProductVariantRepository($this->db))->installed()) $this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE', $key);
            $current = $this->db->queryFirstRow(
                'SELECT revision_number FROM shop_product_revisions WHERE product_key=%s AND language=%s
                 AND active_product_key IS NOT NULL LIMIT 1 FOR UPDATE', $key, $language
            );
            if ($current === null) {
                throw new InvalidArgumentException('Produkt už neexistuje.');
            }
            if ((int) $current['revision_number'] !== $expectedRevision) {
                throw new RuntimeException('Produkt se mezitím změnil. Obnov stránku a zkus to znovu.');
            }
            if ((new ProductRelationRepository($this->db, $this->languages))->installed()) {
                $this->db->query('DELETE FROM shop_product_relations WHERE language=%s AND (product_a=%s OR product_b=%s)', $language, $key, $key);
            }
            $this->db->query(
                'DELETE FROM shop_product_revisions WHERE product_key=%s AND language=%s',
                $key, $language
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function saveRevision(array $fields, ?string $key = null, ?int $expected = null,
        ?array $suppliers = null, ?string $newProductKey = null, ?array $inventory = null): array
    {
        $language = (string) ($fields['language'] ?? '');
        $name = trim((string) ($fields['name'] ?? ''));
        $slug = trim((string) ($fields['slug'] ?? ''));
        if ($slug === '' && $name !== '') {
            $slug = Slugger::fromTitle($name);
        }
        $brand = trim((string) ($fields['brand'] ?? ''));
        $summary = trim((string) ($fields['summary'] ?? ''));
        $description = (string) ($fields['description'] ?? '');
        $details = ProductDetails::fromForm($fields);
        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $categoryPath = (string) ($fields['category_path'] ?? '');
        if (!CategoryPath::valid($categoryPath)) {
            throw new InvalidArgumentException('Vyber kategorii ze seznamu.');
        }
        [$category, $subcategory] = CategoryPath::forStorage($categoryPath);
        $price = filter_var($fields['price_czk'] ?? null, FILTER_VALIDATE_INT);
        $image = trim((string) ($fields['image_path'] ?? ''));
        $sizes = trim((string) ($fields['sizes'] ?? ''));
        $stock = (string) ($fields['stock_status'] ?? '');
        $published = (bool) ($fields['published'] ?? false);

        if (!in_array($language, $this->languages, true) ||
            preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 190 ||
            $name === '' || preg_match('/^.{1,255}$/usD', $name) !== 1 ||
            preg_match('/^.{0,120}$/usD', $brand) !== 1 ||
            strlen($summary) > 65535 || strlen($sizes) > 255 ||
            $price === false || $price < 1 || $price > 10000000 ||
            !in_array($stock, self::STOCK, true)) {
            throw new InvalidArgumentException('Invalid product details. Check the name, category, price and slug.');
        }
        if ($this->categories === null || $this->categories->findForAdmin($language, $categoryPath) === null) {
            throw new InvalidArgumentException('Vybraná kategorie v tomto jazyce neexistuje.');
        }
        // New image references must point to the local media library.
        if (!ProductDetails::imagePath($image)) {
            throw new InvalidArgumentException('Vyber nahranou fotografii ve složce images/.');
        }
        if (strlen($image) > 1000) {
            throw new InvalidArgumentException('The image path is too long.');
        }
        if ($key !== null && preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid product key.');
        }
        if (($key === null && $expected !== null) || ($key !== null && ($expected === null || $expected < 0))) {
            throw new InvalidArgumentException('An existing product requires its last revision number.');
        }
        if (!$this->detailsColumnExists()) {
            throw new RuntimeException('V databázi chybí shop_product_revisions.details_json. '
                . 'V phpMyAdmin vyber databázi z config/database.php a spusť: '
                . 'ALTER TABLE shop_product_revisions ADD COLUMN details_json LONGTEXT NULL AFTER description;');
        }

        $creating = $key === null;
        if ($newProductKey !== null && (!$creating || preg_match('/^[a-f0-9]{32}$/D', $newProductKey) !== 1)) {
            throw new InvalidArgumentException('Neplatná identita importovaného produktu.');
        }
        $key ??= $newProductKey ?? bin2hex(random_bytes(16));
        $variantRepository = new ProductVariantRepository($this->db);
        if (isset($fields['variant_inventory'])) $variantRepository->install();
        $this->db->startTransaction();
        try {
            if ($this->stock !== null || $variantRepository->installed()) {
                (new ProductStockRepository($this->db))->ensure($key);
                $this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE', $key);
            }
            if ($creating && $this->db->queryFirstRow('SELECT product_key FROM shop_product_revisions WHERE product_key=%s LIMIT 1 FOR UPDATE', $key) !== null) {
                throw new InvalidArgumentException('Produkt s tímto klíčem již existuje.');
            }
            if ($expected === 0 && $this->db->queryFirstRow(
                'SELECT product_key FROM shop_product_revisions WHERE product_key=%s LIMIT 1 FOR UPDATE', $key
            ) === null) {
                throw new InvalidArgumentException('Nelze vytvořit překlad: původní produkt neexistuje.');
            }
            $previous = $this->db->queryFirstRow(
                'SELECT id, revision_number FROM shop_product_revisions WHERE product_key=%s
                 AND language=%s AND active_product_key IS NOT NULL LIMIT 1 FOR UPDATE', $key, $language
            );
            if ($expected !== null && ($previous === null ? $expected !== 0 :
                (int) $previous['revision_number'] !== $expected)) {
                throw new RuntimeException('This product changed since you opened it. Reload before saving.');
            }
            $duplicate = $this->db->queryFirstRow(
                'SELECT product_key FROM shop_product_revisions WHERE language=%s AND active_slug=%s LIMIT 1',
                $language, $slug
            );
            if ($duplicate !== null && $duplicate['product_key'] !== $key) {
                throw new InvalidArgumentException('Tato adresa produktu se již používá. Doplň do pole Adresa jiný slug.');
            }
            $revision = $previous === null ? 1 : (int) $previous['revision_number'] + 1;
            if ($previous !== null) {
                $this->db->query(
                    'UPDATE shop_product_revisions SET active_product_key=NULL, active_slug=NULL WHERE id=%i',
                    $previous['id']
                );
            }
            $this->db->insert('shop_product_revisions', [
                'product_key' => $key, 'active_product_key' => $key,
                'language' => $language, 'revision_number' => $revision,
                'slug' => $slug, 'active_slug' => $slug,
                'name' => $name, 'brand' => $brand, 'summary' => $summary, 'description' => $description,
                'details_json' => $detailsJson,
                'category' => $category, 'subcategory' => $subcategory,
                'price_czk' => $price, 'image_path' => $image, 'sizes' => $sizes,
                'stock_status' => $stock, 'published' => (int) $published,
            ]);
            $id = $this->db->insertId();
            $this->stock?->ensure($key);
            if (isset($fields['variant_inventory'])) $variantRepository->apply($key, $fields['variant_inventory']);
            if ($inventory !== null) {
                if ($variantRepository->snapshot($key, true)['enabled']) throw new InvalidArgumentException('Počet kusů tohoto produktu nastav v jednotlivých variantách.');
                $quantity = $inventory['quantity'] ?? null;
                $expectedQuantity = $inventory['expected_quantity'] ?? null;
                if (!is_int($quantity) || $quantity < 0 || $quantity > 1000000 || !is_int($expectedQuantity) || $expectedQuantity < 0) {
                    throw new InvalidArgumentException('Neplatný počet skladových kusů v importu.');
                }
                $available = $this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE', $key);
                if ($available === null || (int) $available !== $expectedQuantity) {
                    throw new InvalidArgumentException('Sklad se od přípravy importu změnil. Produkt nebyl upraven; připrav nový import.');
                }
                $this->db->query('UPDATE shop_product_inventory SET available_quantity=%i WHERE product_key=%s', $quantity, $key);
            }
            if ($suppliers !== null) (new ProductSupplierLinkRepository($this->db))->replace($key, $suppliers);
            if ($revision > $this->revisionLimit) {
                $this->db->query(
                    'DELETE FROM shop_product_revisions WHERE product_key=%s AND language=%s
                     AND active_product_key IS NULL AND revision_number<=%i',
                    $key, $language, $revision - $this->revisionLimit
                );
            }
            $this->db->commit();
            return ['id' => $id, 'product_key' => $key, 'language' => $language, 'revision_number' => $revision];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
