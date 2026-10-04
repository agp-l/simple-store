<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Content\ContentBody;
use SimpleStore\Content\ContentRepository;

/** Published legal pages only; the prepared mail preserves their current text in the outbox. */
final class OrderLegalDocuments
{
    public const TERMS_SLUG = 'obchodni-podminky';
    public const RETURN_SLUG = 'vymena-a-vraceni-zbozi';
    public const COMPLAINT_SLUG = 'reklamacni-rad';
    private const MAX_TERMS_BYTES = 30000;

    public function __construct(private MeekroDB $db)
    {
    }

    public function capture(int $orderId, array $items): void
    {
        if (!$this->installed()) return; // Existing installations must run the SQL updater.
        $language = self::language($items);
        $page = (new ContentRepository($this->db))->findPublished('page', self::TERMS_SLUG, $language);
        if ($page === null) return;
        $terms = self::bodyText((string) $page['body']);
        if ($terms === '') throw new InvalidArgumentException('Publikované obchodní podmínky nemají žádný text.');
        $this->db->query('INSERT IGNORE INTO shop_order_legal_snapshots
            (order_id, language, terms_document_key, terms_revision, terms_text)
            VALUES (%i, %s, %s, %i, %s)', $orderId, $language,
            (string) $page['document_key'], (int) $page['revision_number'], $terms);
    }

    public function forOrder(array $order, bool $includePublishedLinks = true): array
    {
        $language = self::language(is_array($order['items'] ?? null) ? $order['items'] : []);
        $published = [];
        if ($includePublishedLinks) {
            $contents = new ContentRepository($this->db);
            foreach ([self::TERMS_SLUG, self::RETURN_SLUG, self::COMPLAINT_SLUG] as $slug) {
                if ($contents->findPublished('page', $slug, $language) !== null) $published[] = $slug;
            }
        }
        $snapshot = $this->installed() ? $this->db->queryFirstRow(
            'SELECT terms_text FROM shop_order_legal_snapshots WHERE order_id=%i LIMIT 1',
            (int) ($order['id'] ?? 0)) : null;
        return ['language' => $language, 'slugs' => $published,
            'terms' => (string) ($snapshot['terms_text'] ?? '')];
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_order_legal_snapshots') > 0;
    }

    public static function bodyText(string $body): string
    {
        $parts = [];
        foreach (ContentBody::decode($body) as $section) {
            if ($section['type'] === 'image') {
                throw new InvalidArgumentException('Obchodní podmínky obsahují obrázek, který nelze uložit do textového potvrzení objednávky.');
            }
            if (trim($section['heading']) !== '') $parts[] = trim($section['heading']);
            if (trim($section['body']) !== '') $parts[] = trim($section['body']);
        }
        $terms = implode("\n\n", $parts);
        if (strlen($terms) > self::MAX_TERMS_BYTES) {
            throw new InvalidArgumentException('Obchodní podmínky jsou příliš dlouhé pro e-mail. Zkrať je v editoru stránky.');
        }
        return $terms;
    }

    private static function language(array $items): string
    {
        $language = (string) ($items[0]['language'] ?? 'cs');
        return in_array($language, ['cs', 'en'], true) ? $language : 'cs';
    }
}
