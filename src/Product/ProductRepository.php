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
    private const STOCK = ['in_stock', 'on_order', 'out_of_stock'];

    public function __construct(
        private MeekroDB $db,
        private array $languages = ['cs'],
        private ?CategoryRepository $categories = null,
        private int $revisionLimit = 50
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
        int $limit = 12
    ): array
    {
        if ($offset < 0 || $offset > 100000 || $limit < 1 || $limit > 48) {
            throw new InvalidArgumentException('Invalid catalog page.');
        }
        $where = 'language=%s AND published=1 AND active_product_key IS NOT NULL';
        $values = [$language];
        if ($category !== null) {
            if (!CategoryPath::valid($category)) {
                throw new InvalidArgumentException('Invalid category path.');
            }
            [$root, $child] = CategoryPath::forStorage($category);
            $parts = [];
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
            $where .= ' AND (' . implode(' OR ', $parts) . ')';
        }
        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND (LOCATE(%s, name)>0 OR LOCATE(%s, brand)>0 OR LOCATE(%s, summary)>0)';
            array_push($values, $search, $search, $search);
        }
        $order = match ($sort) {
            'price-asc' => 'price_czk ASC, id DESC',
            'price-desc' => 'price_czk DESC, id DESC',
            'name' => 'name ASC, id DESC',
            default => 'id DESC',
        };
        array_push($values, $limit + 1, $offset);
        $rows = $this->db->query(
            'SELECT slug, name, brand, summary, details_json, category, subcategory,
                    price_czk, image_path, sizes, stock_status
             FROM product_revisions WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT %i OFFSET %i',
            ...$values
        );
        $hasMore = count($rows) > $limit;
        return ['items' => array_slice($rows, 0, $limit), 'nextOffset' => $hasMore ? $offset + $limit : null];
    }

    public function findPublished(string $slug, string $language): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM product_revisions WHERE slug=%s AND language=%s AND published=1
             AND active_product_key IS NOT NULL LIMIT 1', $slug, $language
        );
    }

    /** This lookup is for authenticated preview and never belongs on a public route. */
    public function findCurrentBySlug(string $slug, string $language): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM product_revisions WHERE active_slug=%s AND language=%s
             AND active_product_key IS NOT NULL LIMIT 1', $slug, $language
        );
    }

    public function currentProducts(): array
    {
        return $this->db->query(
            'SELECT product_key, language, slug, name, category, subcategory,
                    price_czk, published, revision_number, saved_at
             FROM product_revisions WHERE active_product_key IS NOT NULL ORDER BY id DESC'
        );
    }

    public function current(string $key, string $language): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM product_revisions WHERE product_key=%s AND language=%s
             AND active_product_key IS NOT NULL LIMIT 1', $key, $language
        );
    }

    public function history(string $key, string $language): array
    {
        return $this->db->query(
            'SELECT * FROM product_revisions WHERE product_key=%s AND language=%s
             ORDER BY revision_number DESC', $key, $language
        );
    }

    /** Preview the history without loading every past description and gallery. */
    public function historySummary(string $key, string $language): array
    {
        return $this->db->query(
            'SELECT revision_number, name, saved_at, active_product_key
             FROM product_revisions WHERE product_key=%s AND language=%s
             ORDER BY revision_number DESC', $key, $language
        );
    }

    public function revision(string $key, string $language, int $number): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM product_revisions WHERE product_key=%s AND language=%s
             AND revision_number=%i LIMIT 1', $key, $language, $number
        );
    }

    public function detailsColumnExists(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
            'product_revisions', 'details_json'
        ) > 0;
    }

    public function saveRevision(array $fields, ?string $key = null, ?int $expected = null): array
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
        if ($this->categories === null || $this->categories->find($language, $categoryPath) === null) {
            throw new InvalidArgumentException('Vybraná kategorie v tomto jazyce neexistuje nebo není zapnutá.');
        }
        // An HTTPS URL or a relative path inside images/ is safe to put in an escaped img src.
        if (!ProductDetails::imagePath($image)) {
            throw new InvalidArgumentException('Use an images/filename.webp path or an HTTPS image URL.');
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
            throw new RuntimeException('V databázi chybí product_revisions.details_json. '
                . 'V phpMyAdmin vyber databázi z config/database.php a spusť: '
                . 'ALTER TABLE product_revisions ADD COLUMN details_json LONGTEXT NULL AFTER description;');
        }

        $key ??= bin2hex(random_bytes(16));
        $this->db->startTransaction();
        try {
            if ($expected === 0 && $this->db->queryFirstRow(
                'SELECT product_key FROM product_revisions WHERE product_key=%s LIMIT 1 FOR UPDATE', $key
            ) === null) {
                throw new InvalidArgumentException('Nelze vytvořit překlad: původní produkt neexistuje.');
            }
            $previous = $this->db->queryFirstRow(
                'SELECT id, revision_number FROM product_revisions WHERE product_key=%s
                 AND language=%s AND active_product_key IS NOT NULL LIMIT 1 FOR UPDATE', $key, $language
            );
            if ($expected !== null && ($previous === null ? $expected !== 0 :
                (int) $previous['revision_number'] !== $expected)) {
                throw new RuntimeException('This product changed since you opened it. Reload before saving.');
            }
            $duplicate = $this->db->queryFirstRow(
                'SELECT product_key FROM product_revisions WHERE language=%s AND active_slug=%s LIMIT 1',
                $language, $slug
            );
            if ($duplicate !== null && $duplicate['product_key'] !== $key) {
                throw new InvalidArgumentException('Tato adresa produktu se již používá. Doplň do pole Adresa jiný slug.');
            }
            $revision = $previous === null ? 1 : (int) $previous['revision_number'] + 1;
            if ($previous !== null) {
                $this->db->query(
                    'UPDATE product_revisions SET active_product_key=NULL, active_slug=NULL WHERE id=%i',
                    $previous['id']
                );
            }
            $this->db->insert('product_revisions', [
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
            if ($revision > $this->revisionLimit) {
                $this->db->query(
                    'DELETE FROM product_revisions WHERE product_key=%s AND language=%s
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
