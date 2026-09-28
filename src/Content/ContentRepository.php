<?php
declare(strict_types=1);

namespace SimpleStore\Content;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Navigation\Slugger;
use RuntimeException;
use Throwable;

/** Read current content and keep every earlier revision in the same table. */
final class ContentRepository
{
    public function __construct(private MeekroDB $db, private array $languages = ['cs'])
    {
    }

    public function findPublished(string $type, string $slug, string $language): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM content_revisions WHERE type=%s AND slug=%s AND language=%s
             AND active_document_key IS NOT NULL AND published=1 LIMIT 1',
            $type, $slug, $language
        );
    }

    /** Drafts may be requested only by authenticated code in index.php. */
    public function findCurrentBySlug(string $type, string $slug, string $language): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM content_revisions WHERE type=%s AND slug=%s AND language=%s
             AND active_document_key IS NOT NULL LIMIT 1',
            $type, $slug, $language
        );
    }

    public function publishedPosts(string $language): array
    {
        return $this->db->query(
            'SELECT document_key, language, slug, title, summary, saved_at
             FROM content_revisions WHERE type=%s AND language=%s
             AND active_document_key IS NOT NULL AND published=1
             ORDER BY saved_at DESC, id DESC',
            'post', $language
        );
    }

    public function menuPages(string $language): array
    {
        return $this->db->query(
            'SELECT slug, title FROM content_revisions WHERE type=%s AND language=%s
             AND active_document_key IS NOT NULL AND published=1 AND visible_in_menu=1
             ORDER BY menu_order ASC, title ASC',
            'page', $language
        );
    }

    /** List current versions, including drafts, for the editor only. */
    public function currentDocuments(): array
    {
        return $this->db->query(
            'SELECT document_key, language, type, slug, title, revision_number, published, saved_at
             FROM content_revisions WHERE active_document_key IS NOT NULL
             ORDER BY saved_at DESC, id DESC'
        );
    }

    public function currentDocument(string $documentKey, string $language): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM content_revisions
             WHERE document_key=%s AND language=%s AND active_document_key IS NOT NULL LIMIT 1',
            $documentKey, $language
        );
    }

    public function revision(string $documentKey, string $language, int $number): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM content_revisions
             WHERE document_key=%s AND language=%s AND revision_number=%i LIMIT 1',
            $documentKey, $language, $number
        );
    }

    /** The history method is for trusted administrative code, never a public route. */
    public function history(string $documentKey, string $language): array
    {
        return $this->db->query(
            'SELECT * FROM content_revisions WHERE document_key=%s AND language=%s
             ORDER BY revision_number DESC',
            $documentKey, $language
        );
    }

    /** Insert a snapshot; the previous snapshot remains available in history(). */
    public function saveRevision(array $fields, ?string $documentKey = null, ?int $expectedRevision = null): array
    {
        $type = (string) ($fields['type'] ?? '');
        $language = (string) ($fields['language'] ?? '');
        $title = trim((string) ($fields['title'] ?? ''));
        $slug = trim((string) ($fields['slug'] ?? ''));
        if ($slug === '' && $title !== '') {
            $slug = Slugger::fromTitle($title);
        }
        $summary = trim((string) ($fields['summary'] ?? ''));
        $body = (string) ($fields['body'] ?? '');
        $published = (bool) ($fields['published'] ?? false);
        $visibleInMenu = (bool) ($fields['visible_in_menu'] ?? false);
        $menuOrder = (int) ($fields['menu_order'] ?? 0);

        if (!in_array($type, ['page', 'post'], true) || !in_array($language, $this->languages, true)) {
            throw new InvalidArgumentException('Unsupported content type or language.');
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 190 ||
            ($type === 'page' && (in_array($slug, ['blog', 'produkt', 'kategorie-produktu'], true) ||
                in_array($slug, $this->languages, true)))) {
            throw new InvalidArgumentException('Invalid or reserved slug.');
        }
        if ($title === '' || preg_match('/^.{1,255}$/usD', $title) !== 1 || strlen($summary) > 65535 ||
            $menuOrder < 0 || $menuOrder > 65535) {
            throw new InvalidArgumentException('Invalid title, summary or menu order.');
        }
        if ($documentKey !== null && preg_match('/^[a-f0-9]{32}$/D', $documentKey) !== 1) {
            throw new InvalidArgumentException('Invalid document key.');
        }
        if ($documentKey === null && $expectedRevision !== null) {
            throw new InvalidArgumentException('A new document has no expected revision.');
        }
        if ($documentKey !== null && ($expectedRevision === null || $expectedRevision < 0)) {
            throw new InvalidArgumentException('An existing key requires its last revision number (0 for a new translation).');
        }

        $documentKey ??= bin2hex(random_bytes(16));
        $this->db->startTransaction();
        try {
            $document = $this->db->queryFirstRow(
                'SELECT type FROM content_revisions WHERE document_key=%s LIMIT 1 FOR UPDATE',
                $documentKey
            );
            if ($document !== null && $document['type'] !== $type) {
                throw new InvalidArgumentException('A translation must keep the document type.');
            }
            $previous = $this->db->queryFirstRow(
                'SELECT id, type, revision_number FROM content_revisions
                 WHERE document_key=%s AND language=%s AND active_document_key IS NOT NULL
                 LIMIT 1 FOR UPDATE',
                $documentKey, $language
            );
            if ($expectedRevision !== null &&
                ($previous === null ? $expectedRevision !== 0 :
                    (int) $previous['revision_number'] !== $expectedRevision || $previous['type'] !== $type)) {
                throw new RuntimeException('This document changed since you opened it. Reload before saving.');
            }

            $duplicate = $this->db->queryFirstRow(
                'SELECT document_key FROM content_revisions WHERE type=%s AND language=%s AND active_slug=%s LIMIT 1',
                $type, $language, $slug
            );
            if ($duplicate !== null && $duplicate['document_key'] !== $documentKey) {
                throw new InvalidArgumentException('Tato adresa se již používá. Zadej jiný slug.');
            }

            $revision = $previous === null ? 1 : (int) $previous['revision_number'] + 1;
            if ($previous !== null) {
                $this->db->query(
                    'UPDATE content_revisions SET active_document_key=NULL, active_slug=NULL WHERE id=%i',
                    $previous['id']
                );
            }
            $this->db->insert('content_revisions', [
                'document_key' => $documentKey,
                'active_document_key' => $documentKey,
                'language' => $language,
                'revision_number' => $revision,
                'type' => $type,
                'slug' => $slug,
                'active_slug' => $slug,
                'title' => $title,
                'summary' => $summary,
                'body' => $body,
                'published' => (int) $published,
                'visible_in_menu' => (int) $visibleInMenu,
                'menu_order' => $menuOrder,
            ]);
            $id = $this->db->insertId();
            $this->db->commit();
            return ['id' => $id, 'document_key' => $documentKey, 'language' => $language, 'revision_number' => $revision];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
