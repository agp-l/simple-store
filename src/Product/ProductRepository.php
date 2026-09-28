<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use Throwable;

/** Store each product edit as a new complete snapshot. */
final class ProductRepository
{
    private const CATEGORIES = ['batohy', 'stany', 'spacaky', 'vybaveni', 'obleceni', 'boty'];
    private const BACKPACK_TYPES = ['do-25', '25-50', 'nad-50', 'prislusenstvi'];
    private const STOCK = ['in_stock', 'on_order', 'out_of_stock'];

    public function __construct(private MeekroDB $db, private array $languages = ['cs'])
    {
    }

    public function published(string $language): array
    {
        return $this->db->query(
            'SELECT * FROM product_revisions WHERE language=%s AND published=1
             AND active_product_key IS NOT NULL ORDER BY id DESC', $language
        );
    }

    public function findPublished(string $slug, string $language): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM product_revisions WHERE slug=%s AND language=%s AND published=1
             AND active_product_key IS NOT NULL LIMIT 1', $slug, $language
        );
    }

    public function currentProducts(): array
    {
        return $this->db->query(
            'SELECT product_key, language, slug, name, price_czk, published, revision_number, saved_at
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

    public function revision(string $key, string $language, int $number): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM product_revisions WHERE product_key=%s AND language=%s
             AND revision_number=%i LIMIT 1', $key, $language, $number
        );
    }

    public function saveRevision(array $fields, ?string $key = null, ?int $expected = null): array
    {
        $language = (string) ($fields['language'] ?? '');
        $slug = (string) ($fields['slug'] ?? '');
        $name = trim((string) ($fields['name'] ?? ''));
        $brand = trim((string) ($fields['brand'] ?? ''));
        $summary = trim((string) ($fields['summary'] ?? ''));
        $description = (string) ($fields['description'] ?? '');
        $category = (string) ($fields['category'] ?? '');
        $subcategory = (string) ($fields['subcategory'] ?? '');
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
            !in_array($category, self::CATEGORIES, true) ||
            ($category === 'batohy' ? ($subcategory !== '' && !in_array($subcategory, self::BACKPACK_TYPES, true)) : $subcategory !== '') ||
            $price === false || $price < 1 || $price > 10000000 ||
            !in_array($stock, self::STOCK, true)) {
            throw new InvalidArgumentException('Invalid product details. Check the name, category, price and slug.');
        }
        // An HTTPS URL or a relative path inside images/ is safe to put in an escaped img src.
        if (!preg_match('~^images/[A-Za-z0-9_-]+\.(?:webp|jpg|jpeg|png|avif)$~iD', $image) &&
            !(filter_var($image, FILTER_VALIDATE_URL) && parse_url($image, PHP_URL_SCHEME) === 'https')) {
            throw new InvalidArgumentException('Use an images/filename.webp path or an HTTPS image URL.');
        }
        if ($key !== null && preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid product key.');
        }
        if (($key === null && $expected !== null) || ($key !== null && ($expected === null || $expected < 0))) {
            throw new InvalidArgumentException('An existing product requires its last revision number.');
        }

        $key ??= bin2hex(random_bytes(16));
        $this->db->startTransaction();
        try {
            $previous = $this->db->queryFirstRow(
                'SELECT id, revision_number FROM product_revisions WHERE product_key=%s
                 AND language=%s AND active_product_key IS NOT NULL LIMIT 1 FOR UPDATE', $key, $language
            );
            if ($expected !== null && ($previous === null ? $expected !== 0 :
                (int) $previous['revision_number'] !== $expected)) {
                throw new RuntimeException('This product changed since you opened it. Reload before saving.');
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
                'category' => $category, 'subcategory' => $subcategory,
                'price_czk' => $price, 'image_path' => $image, 'sizes' => $sizes,
                'stock_status' => $stock, 'published' => (int) $published,
            ]);
            $id = $this->db->insertId();
            $this->db->commit();
            return ['id' => $id, 'product_key' => $key, 'language' => $language, 'revision_number' => $revision];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
