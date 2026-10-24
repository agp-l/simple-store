<?php
declare(strict_types=1);

namespace SimpleStore\Content;

use InvalidArgumentException;
use JsonException;
use SimpleStore\Editing\CardSummary;
use SimpleStore\Navigation\Slugger;

/** Portable page/article snapshots; database identities are used only for explicit updates. */
final class ContentImport
{
    public const FORMAT = 'simple-store.content.v1';
    public const LIMIT = 4194304;

    public static function decode(string $json): array
    {
        if (strlen($json) > self::LIMIT) throw new InvalidArgumentException('JSON může mít nejvýše 4 MiB.');
        $json = trim($json);
        if (str_starts_with($json, "\xEF\xBB\xBF")) $json = trim(substr($json, 3));
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/sD', $json, $match)) $json = $match[1];
        try {
            $object = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Neplatný JSON: ' . $error->getMessage());
        }
        if (!$object instanceof \stdClass || !($object->content ?? null) instanceof \stdClass) {
            throw new InvalidArgumentException('Očekávám JSON objekt s objektem content.');
        }
        if (isset($object->content->sections) && !is_array($object->content->sections)) {
            throw new InvalidArgumentException('sections musí být seznam bloků.');
        }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::keys($data, ['format', 'document_key', 'revision', 'language', 'type', 'content']);
        if (($data['format'] ?? null) !== self::FORMAT ||
            !in_array($data['type'] ?? null, ['page', 'post'], true) || !is_string($data['language'] ?? null)) {
            throw new InvalidArgumentException('Uveď format simple-store.content.v1, type page nebo post a language (např. cs).');
        }
        if (array_key_exists('document_key', $data) && (!is_string($data['document_key']) ||
            preg_match('/^[a-f0-9]{32}$/D', $data['document_key']) !== 1)) {
            throw new InvalidArgumentException('document_key musí obsahovat 32 znaků z exportu.');
        }
        if (array_key_exists('revision', $data) && (!is_int($data['revision']) || $data['revision'] < 1)) {
            throw new InvalidArgumentException('revision musí být kladné celé číslo z exportu.');
        }
        return $data;
    }

    /** Missing properties preserve the current revision; a new import is always a private draft. */
    public static function fields(array $data, array $languages, string $mode, ?array $current = null): array
    {
        if (!in_array($mode, ['new', 'update'], true) || !in_array($data['language'], $languages, true)) {
            throw new InvalidArgumentException('Neplatný způsob importu nebo nepodporovaný jazyk.');
        }
        if ($mode === 'update' && ($current === null || !isset($data['document_key'], $data['revision']) ||
            $current['document_key'] !== $data['document_key'] || $current['language'] !== $data['language'] ||
            $current['type'] !== $data['type'] || (int) $current['revision_number'] !== $data['revision'])) {
            throw new InvalidArgumentException('Dokument neexistuje nebo se mezitím změnil. Pro aktualizaci použij nový export; pro kopii zvol Nový koncept.');
        }
        $fields = $mode === 'update' ? [
            'title' => $current['title'], 'slug' => $current['slug'], 'summary' => $current['summary'],
            'body' => $current['body'], 'published' => (bool) $current['published'],
            'visible_in_menu' => (bool) $current['visible_in_menu'], 'menu_order' => (int) $current['menu_order'],
        ] : ['title' => '', 'slug' => '', 'summary' => '', 'body' => '',
            'published' => false, 'visible_in_menu' => false, 'menu_order' => 0];
        $fields['type'] = $data['type'];
        $fields['language'] = $data['language'];
        $document = $data['content'];
        self::keys($document, ['title', 'slug', 'summary', 'body', 'sections', 'published', 'visible_in_menu', 'menu_order', 'cover_path']);
        foreach (['title', 'slug', 'summary'] as $field) {
            if (!array_key_exists($field, $document)) continue;
            if (!is_string($document[$field])) throw new InvalidArgumentException($field . ' musí být text.');
            $fields[$field] = trim($document[$field]);
        }
        foreach (['published', 'visible_in_menu'] as $field) {
            if (!array_key_exists($field, $document)) continue;
            if (!is_bool($document[$field])) throw new InvalidArgumentException($field . ' musí být true nebo false.');
            $fields[$field] = $document[$field];
        }
        if (array_key_exists('menu_order', $document)) {
            if (!is_int($document['menu_order']) || $document['menu_order'] < 0 || $document['menu_order'] > 65535) {
                throw new InvalidArgumentException('menu_order musí být celé číslo od 0 do 65535.');
            }
            $fields['menu_order'] = $document['menu_order'];
        }
        if (array_key_exists('body', $document) && array_key_exists('sections', $document)) {
            throw new InvalidArgumentException('Použij buď sections pro bloky, nebo body pro prostý text.');
        }
        if (array_key_exists('body', $document)) {
            if (!is_string($document['body'])) throw new InvalidArgumentException('body musí být prostý text.');
            $fields['body'] = ContentBody::encode($document['body'] === '' ? [] :
                [['type' => 'text', 'heading' => '', 'body' => $document['body']]]);
        }
        if (array_key_exists('sections', $document)) {
            if (!is_array($document['sections']) || array_values($document['sections']) !== $document['sections']) {
                throw new InvalidArgumentException('sections musí být seznam bloků.');
            }
            foreach ($document['sections'] as $section) {
                if (!is_array($section)) throw new InvalidArgumentException('Každý blok musí být objekt.');
                self::keys($section, ['type', 'heading', 'body']);
            }
            // Keep unchanged legacy content byte-for-byte, including previously supported image links.
            $fields['body'] = $mode === 'update' && $document['sections'] === ContentBody::decode($current['body'])
                ? $current['body'] : ContentBody::encode($document['sections']);
        }
        $cover = $mode === 'update' ? ContentBody::coverSetting($current['body']) : null;
        if (array_key_exists('cover_path', $document)) {
            $cover = $document['cover_path'];
            if ($cover !== null && !is_string($cover)) throw new InvalidArgumentException('cover_path musí být cesta k obrázku, prázdný text nebo null.');
        }
        if ($cover !== ContentBody::coverSetting($fields['body'])) {
            $fields['body'] = ContentBody::encode(ContentBody::decode($fields['body']), $cover);
        }
        if ($fields['slug'] === '') $fields['slug'] = Slugger::fromTitle($fields['title']);
        if (preg_match('/^.{1,255}$/usD', $fields['title']) !== 1 || strlen($fields['summary']) > 65535) {
            throw new InvalidArgumentException('Vyplň název (nejvýše 255 znaků) a zkontroluj délku úvodu.');
        }
        if (strlen($fields['slug']) > 190 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $fields['slug']) !== 1 ||
            ($fields['type'] === 'page' && in_array($fields['slug'], array_merge(['blog', 'produkt', 'kategorie-produktu'], $languages), true))) {
            throw new InvalidArgumentException('Neplatná nebo rezervovaná adresa. slug smí mít nejvýše 190 znaků: malá písmena, čísla a pomlčky.');
        }
        if ($fields['type'] === 'post' && ($mode === 'new' || $fields['summary'] !== $current['summary'])) {
            CardSummary::validate($fields['summary']);
        }
        if ($mode === 'new') {
            $fields['published'] = false;
            $fields['visible_in_menu'] = false;
        }
        if ($fields['type'] === 'post') {
            $fields['visible_in_menu'] = false;
            $fields['menu_order'] = 0;
        }
        return $fields;
    }

    public static function export(array $row): string
    {
        return json_encode([
            'format' => self::FORMAT, 'document_key' => $row['document_key'],
            'revision' => (int) $row['revision_number'], 'language' => $row['language'], 'type' => $row['type'],
            'content' => [
                'title' => $row['title'], 'slug' => $row['slug'], 'summary' => $row['summary'],
                'published' => (bool) $row['published'], 'visible_in_menu' => (bool) $row['visible_in_menu'],
                'cover_path' => ContentBody::coverSetting($row['body']),
                'menu_order' => (int) $row['menu_order'], 'sections' => ContentBody::decode($row['body']),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new InvalidArgumentException('JSON obsahuje neznámá pole: ' . implode(', ', array_diff(array_keys($data), $allowed)));
        }
    }
}
