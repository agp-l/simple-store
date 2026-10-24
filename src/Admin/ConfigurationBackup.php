<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Accounting\{MailContent, MailSettingsRepository};
use SimpleStore\Category\{CategoryPath, CategoryRepository};
use SimpleStore\Content\{ContentRepository, SiteCopyRepository};
use SimpleStore\Navigation\MenuDefinitionRepository;

/** Explicit, versioned settings snapshots. Never imports products, orders or credentials. */
final class ConfigurationBackup
{
    public const FORMAT = 'simple-store-settings';
    public const LIMIT = 2097152;
    public const KINDS = ['navigation', 'site-texts', 'email-texts'];

    public function __construct(private MeekroDB $db, private array $languages, private array $menuDefaults) {}

    public static function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function snapshot(string $kind): array
    {
        if (!in_array($kind, self::KINDS, true)) throw new InvalidArgumentException('Neznámý druh zálohy.');
        $data = [];
        if ($kind === 'navigation') {
            $categories = new CategoryRepository($this->db);
            $menus = new MenuDefinitionRepository($this->db, $this->menuDefaults, $categories);
            $content = new ContentRepository($this->db, $this->languages);
            foreach ($this->languages as $language) {
                $data[$language] = ['categories' => [], 'menus' => [], 'pages' => []];
                foreach ($categories->allForAdmin($language) as $row) {
                    $data[$language]['categories'][$row['path']] = ['title' => $row['title'],
                        'sort_order' => (int) $row['sort_order'], 'enabled' => (bool) $row['enabled']];
                }
                foreach ($menus->settings($language) as $slot => $settings) {
                    $items = [];
                    foreach ($menus->itemsForAdmin($language, $slot) as $item) {
                        unset($item['depth']);
                        $items[] = $item;
                    }
                    $data[$language]['menus'][$slot] = ['source' => $settings['source'],
                        'parent' => $settings['parent'] ?? '', 'include_blog' => (bool) ($settings['include_blog'] ?? false),
                        'title' => $settings['title'] ?? 'Informace', 'items' => self::orderedItems($items)];
                }
                foreach ($content->pagesForMenu($language) as $page) {
                    $data[$language]['pages'][$page['slug']] = ['visible' => (bool) $page['visible_in_menu'], 'sort_order' => (int) $page['menu_order']];
                }
            }
        } elseif ($kind === 'site-texts') {
            $copy = new SiteCopyRepository($this->db);
            foreach ($this->languages as $language) $data[$language] = $copy->load($language);
        } else {
            $configuration = (new MailSettingsRepository($this->db))->load();
            $data = ['templates' => [], 'copy' => $configuration['settings']['copy']];
            foreach ($configuration['templates'] as $code => $template) {
                $data['templates'][$code] = ['subject' => $template['subject'], 'message' => $template['message']];
            }
        }
        return ['format' => self::FORMAT, 'version' => 1, 'kind' => $kind, 'data' => $data];
    }

    public function decode(string $json, string $kind): array
    {
        if ($json === '' || strlen($json) > self::LIMIT) throw new InvalidArgumentException('Vyber JSON zálohu do 2 MiB.');
        try { $backup = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new InvalidArgumentException('Soubor neobsahuje platný JSON.'); }
        if (!is_array($backup) || ($backup['format'] ?? '') !== self::FORMAT || ($backup['version'] ?? null) !== 1 ||
            ($backup['kind'] ?? '') !== $kind || !is_array($backup['data'] ?? null)) {
            throw new InvalidArgumentException('Tato záloha patří do jiné oblasti nebo používá nepodporovaný formát.');
        }
        self::keys($backup, ['format', 'version', 'kind', 'data']);
        if ($kind === 'email-texts') {
            self::keys($backup['data'], ['templates', 'copy']);
            $data = $backup['data'];
            if (!is_array($data['templates'] ?? null) || !is_array($data['copy'] ?? null)) throw new InvalidArgumentException('Chybí texty e-mailů.');
            self::keys($data['templates'], array_keys(MailContent::definitions()));
            self::keys($data['copy'], array_keys(MailContent::defaults()));
            if ($data['templates'] === [] && $data['copy'] === []) throw new InvalidArgumentException('Záloha je prázdná.');
            foreach ($data['templates'] as $code => $entry) {
                if (!is_array($entry)) throw new InvalidArgumentException('Neplatná šablona e-mailu.');
                self::keys($entry, ['subject', 'message']);
                foreach (['subject', 'message'] as $field) if (!is_string($entry[$field] ?? null)) throw new InvalidArgumentException('Šabloně chybí předmět nebo text.');
            }
            $current = (new MailSettingsRepository($this->db))->load();
            $templates = $current['templates'];
            foreach ($templates as $code => &$template) $template = ($data['templates'][$code] ?? []) + $template + ['enabled' => false];
            unset($template);
            foreach ($templates as &$template) $template['enabled'] = $template['enabled'] ? '1' : '0';
            unset($template);
            $valid = MailSettingsRepository::validateTemplates($templates, $templates);
            foreach ($data['templates'] as $code => $_) $backup['data']['templates'][$code] = ['subject' => $valid[$code]['subject'], 'message' => $valid[$code]['message']];
            $copy = MailSettingsRepository::validateCopy($data['copy'] + $current['settings']['copy']);
            $backup['data']['copy'] = array_intersect_key($copy, $data['copy']);
            return $backup;
        }
        if ($backup['data'] === []) throw new InvalidArgumentException('Záloha neobsahuje žádný jazyk.');
        foreach ($backup['data'] as $language => &$data) {
            if (!in_array($language, $this->languages, true) || !is_array($data)) throw new InvalidArgumentException('Jazyk zálohy není v tomto obchodě nastaven.');
            if ($kind === 'site-texts') {
                self::keys($data, array_keys(SiteCopyRepository::DEFAULTS));
                if ($data === []) throw new InvalidArgumentException('Záloha textů je prázdná.');
                $valid = SiteCopyRepository::validate($data + (new SiteCopyRepository($this->db))->load($language));
                $data = array_intersect_key($valid, $data);
            } else {
                self::keys($data, ['categories', 'menus', 'pages']);
                foreach (['categories', 'menus', 'pages'] as $key) if (!is_array($data[$key] ?? null)) throw new InvalidArgumentException('Záloze chybí kategorie, menu nebo nastavení stránek.');
                if (count($data['categories']) > 5000 || count($data['pages']) > 5000) throw new InvalidArgumentException('Záloha obsahuje příliš mnoho položek.');
                foreach ($data['categories'] as $path => $category) {
                    if (!CategoryPath::valid((string) $path) || !is_array($category)) throw new InvalidArgumentException('Neplatná adresa kategorie.');
                    self::keys($category, ['title', 'sort_order', 'enabled']);
                    self::text($category['title'] ?? null, 160); self::order($category['sort_order'] ?? null);
                    if (!is_bool($category['enabled'] ?? null)) throw new InvalidArgumentException('Neplatná viditelnost kategorie.');
                }
                $allowedSlots = array_keys((new MenuDefinitionRepository($this->db, $this->menuDefaults))->settings($language));
                foreach ($data['menus'] as $slot => $menu) {
                    if (!in_array($slot, $allowedSlots, true) || !is_array($menu)) throw new InvalidArgumentException('Neznámé umístění menu.');
                    self::validateMenu($slot, $menu);
                    $data['menus'][$slot]['items'] = self::orderedItems($menu['items']);
                }
                foreach ($data['pages'] as $slug => $page) {
                    if (!CategoryPath::valid((string) $slug) || str_contains((string) $slug, '/') || !is_array($page)) throw new InvalidArgumentException('Neplatná stránka v menu.');
                    self::keys($page, ['visible', 'sort_order']); self::order($page['sort_order'] ?? null);
                    if (!is_bool($page['visible'] ?? null)) throw new InvalidArgumentException('Neplatná viditelnost stránky.');
                }
            }
        }
        unset($data);
        return $backup;
    }

    private static function validateMenu(string $slot, array $menu): void
    {
        self::keys($menu, ['source', 'parent', 'include_blog', 'title', 'items']);
        if (!in_array($menu['source'] ?? '', ['categories', 'content', 'manual'], true) ||
            !is_string($menu['parent'] ?? null) || !is_bool($menu['include_blog'] ?? null) || !is_array($menu['items'] ?? null) ||
            count($menu['items']) > 100) throw new InvalidArgumentException('Neplatné nastavení menu.');
        $parent = $menu['parent'];
        if (($parent === '@context' && $slot !== 'category_tabs') || ($parent !== '' && $parent !== '@context' && !CategoryPath::valid($parent)) ||
            ($menu['source'] !== 'categories' && $parent !== '') || ($menu['source'] !== 'content' && $menu['include_blog'])) {
            throw new InvalidArgumentException('Zdroj menu neodpovídá jeho nastavení.');
        }
        self::text($menu['title'] ?? null, 80);
        $items = [];
        foreach ($menu['items'] as $item) {
            if (!is_array($item)) throw new InvalidArgumentException('Neplatný odkaz.');
            self::keys($item, ['id', 'parent_id', 'label', 'target_type', 'target', 'sort_order']);
            if (!is_string($item['id'] ?? null) || !preg_match('/^[a-f0-9]{16}$/D', $item['id']) || isset($items[$item['id']]) ||
                !is_string($item['parent_id'] ?? null) || !is_string($item['target'] ?? null) ||
                !in_array($item['target_type'] ?? '', ['category', 'path', 'external'], true)) throw new InvalidArgumentException('Neplatné nebo duplicitní ID odkazu.');
            self::text($item['label'] ?? null, 80); self::order($item['sort_order'] ?? null);
            $target = $item['target']; $type = $item['target_type'];
            if (($type === 'external' && !MenuDefinitionRepository::validExternal($target)) ||
                ($type === 'category' && !CategoryPath::valid($target)) ||
                ($type === 'path' && $target !== '' && !CategoryPath::valid($target))) throw new InvalidArgumentException('Neplatný nebo nebezpečný cíl odkazu.');
            $items[$item['id']] = $item;
        }
        foreach ($items as $item) {
            $parent = $item['parent_id'];
            for ($depth = 0; $parent !== ''; $depth++) {
                if ($depth >= count($items) || !isset($items[$parent])) throw new InvalidArgumentException('Odkazy obsahují cyklus nebo chybějícího rodiče.');
                $parent = $items[$parent]['parent_id'];
            }
        }
    }

    private static function orderedItems(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['label'], $b['label']) ?: strcmp($a['id'], $b['id']));
        $ordered = [];
        $visit = static function (string $parent) use (&$visit, &$ordered, $items): void {
            foreach ($items as $item) if ($item['parent_id'] === $parent) { $ordered[] = $item; $visit($item['id']); }
        };
        $visit('');
        return $ordered;
    }

    /** The preview and the application use exactly the same operation list. */
    public function plan(array $backup): array
    {
        $current = $this->snapshot($backup['kind']);
        $changes = []; $counts = ['new' => 0, 'changed' => 0, 'same' => 0];
        $add = static function (string $type, string $language, string $key, string $label, mixed $before, mixed $after) use (&$changes, &$counts): void {
            $state = $before === null ? 'new' : (self::canonical($before) === self::canonical($after) ? 'same' : 'changed');
            $counts[$state]++;
            $changes[] = compact('type', 'language', 'key', 'label', 'before', 'after', 'state');
        };
        if ($backup['kind'] === 'navigation') {
            foreach ($backup['data'] as $language => $data) {
                $old = $current['data'][$language];
                $categories = $data['categories'] + $old['categories'];
                foreach ($data['categories'] as $path => $category) {
                    $path = (string) $path;
                    $parent = CategoryPath::parent($path);
                    if ($parent !== '' && !isset($categories[$parent])) throw new InvalidArgumentException('Chybí nadřazená kategorie: ' . $parent);
                    $add('category', $language, $path, $category['title'] . ' · ' . $path, $old['categories'][$path] ?? null, $category);
                }
                foreach ($data['menus'] as $slot => $menu) {
                    $targets = array_column(array_filter($menu['items'], static fn (array $i): bool => $i['target_type'] === 'category'), 'target');
                    if ($menu['source'] === 'categories' && !in_array($menu['parent'], ['', '@context'], true)) $targets[] = $menu['parent'];
                    foreach ($targets as $target) if (!isset($categories[$target])) throw new InvalidArgumentException('Menu odkazuje na neexistující kategorii: ' . $target);
                    $add('menu', $language, $slot, self::slotLabel($slot) . ' · ' . count($menu['items']) . ' vlastních odkazů', $old['menus'][$slot] ?? null, $menu);
                }
                foreach ($data['pages'] as $slug => $page) {
                    $slug = (string) $slug;
                    if (!isset($old['pages'][$slug])) throw new InvalidArgumentException('Chybí stránka „' . $slug . '“. Nejdřív ji obnov v importu stránek; tato záloha obsahuje pouze její umístění v menu.');
                    $add('page', $language, $slug, 'Stránka: ' . $slug, $old['pages'][$slug], $page);
                }
            }
        } elseif ($backup['kind'] === 'site-texts') {
            foreach ($backup['data'] as $language => $data) foreach ($data as $key => $value) $add('site-copy', $language, $key, self::siteLabel($key), $current['data'][$language][$key], $value);
        } else {
            foreach ($backup['data']['templates'] as $key => $value) $add('template', '', $key, MailContent::definitions()[$key]['label'], $current['data']['templates'][$key], $value);
            $labels = [];
            foreach (MailContent::GROUPS as $group => $fields) foreach ($fields as $key => [$label]) $labels[$key] = $group . ': ' . $label;
            foreach ($backup['data']['copy'] as $key => $value) $add('mail-copy', '', $key, $labels[$key] ?? (MailContent::defaults()[$key] ?? $key), $current['data']['copy'][$key], $value);
        }
        return ['changes' => $changes, 'counts' => $counts, 'version' => hash('sha256', self::canonical($current))];
    }

    public static function token(array $backup, string $version, string $csrf): string
    {
        return hash_hmac('sha256', self::canonical($backup) . "\0" . $version, $csrf);
    }

    public function apply(array $backup, string $token, string $csrf): array
    {
        $this->db->startTransaction();
        try {
            // Lock before the fresh read. A stale preview cannot overwrite newer settings.
            $tables = match ($backup['kind']) {
                'navigation' => ['shop_catalog_categories' => 'path', 'shop_navigation_menus' => 'slot', 'shop_content_revisions' => 'id'],
                'site-texts' => ['shop_site_copy' => 'language'],
                'email-texts' => ['shop_mail_settings' => 'id', 'shop_mail_templates' => 'event_code'],
            };
            foreach ($tables as $table => $key) $this->db->query('SELECT ' . $key . ' FROM ' . $table .
                ($table === 'shop_content_revisions' ? ' WHERE active_document_key IS NOT NULL AND type=\'page\'' : '') . ' FOR UPDATE');
            $plan = $this->plan($backup);
            if (!hash_equals(self::token($backup, $plan['version'], $csrf), $token)) throw new InvalidArgumentException('Nastavení nebo soubor se od náhledu změnily. Zobraz nový náhled importu.');
            $mail = $backup['kind'] === 'email-texts' ? (new MailSettingsRepository($this->db))->load() : null;
            foreach ($plan['changes'] as $change) {
                if ($change['state'] === 'same') continue;
                ['type' => $type, 'language' => $language, 'key' => $key, 'after' => $value] = $change;
                if ($type === 'category') {
                    $this->db->query('INSERT INTO shop_catalog_categories (language,path,title,sort_order,enabled) VALUES (%s,%s,%s,%i,%i)
                        ON DUPLICATE KEY UPDATE title=VALUES(title),sort_order=VALUES(sort_order),enabled=VALUES(enabled)',
                        $language, $key, $value['title'], $value['sort_order'], (int) $value['enabled']);
                } elseif ($type === 'menu') {
                    $this->db->query('INSERT INTO shop_navigation_menus (language,slot,source,parent_path,include_blog,items_json) VALUES (%s,%s,%s,%s,%i,%s)
                        ON DUPLICATE KEY UPDATE source=VALUES(source),parent_path=VALUES(parent_path),include_blog=VALUES(include_blog),items_json=VALUES(items_json)',
                        $language, $key, $value['source'], $value['parent'], (int) $value['include_blog'], self::json(['title' => $value['title'], 'items' => $value['items']]));
                } elseif ($type === 'page') {
                    // A menu restore is a content revision too; preserve body/publication and all older revisions.
                    $page = $this->db->queryFirstRow('SELECT * FROM shop_content_revisions WHERE type=%s AND language=%s AND slug=%s AND active_document_key IS NOT NULL', 'page', $language, $key);
                    $this->db->query('UPDATE shop_content_revisions SET active_document_key=NULL, active_slug=NULL WHERE id=%i', $page['id']);
                    unset($page['id'], $page['saved_at']);
                    $page['revision_number'] = (int) $page['revision_number'] + 1;
                    $page['visible_in_menu'] = (int) $value['visible']; $page['menu_order'] = $value['sort_order'];
                    $this->db->insert('shop_content_revisions', $page);
                } elseif ($type === 'site-copy') {
                    // Written once per language below.
                } elseif ($type === 'template') {
                    $this->db->query('INSERT INTO shop_mail_templates (event_code,enabled,subject,message_text) VALUES (%s,%i,%s,%s)
                        ON DUPLICATE KEY UPDATE subject=VALUES(subject),message_text=VALUES(message_text),updated_at=UTC_TIMESTAMP()',
                        $key, (int) $mail['templates'][$key]['enabled'], $value['subject'], $value['message']);
                } elseif ($type === 'mail-copy') {
                    $mail['settings']['copy'][$key] = $value;
                }
            }
            if ($backup['kind'] === 'site-texts') {
                $copy = new SiteCopyRepository($this->db);
                foreach ($backup['data'] as $language => $values) {
                    if (array_filter($plan['changes'], static fn (array $c): bool => $c['language'] === $language && $c['state'] !== 'same')) $copy->save($language, $values + $copy->load($language));
                }
            }
            if ($mail !== null && array_filter($plan['changes'], static fn (array $c): bool => $c['type'] === 'mail-copy' && $c['state'] !== 'same')) {
                $row = $this->db->queryFirstRow('SELECT settings_json FROM shop_mail_settings WHERE id=%i', 1);
                $settings = $row === null ? [] : json_decode($row['settings_json'], true, 64, JSON_THROW_ON_ERROR);
                $settings['copy'] = $mail['settings']['copy'];
                $this->db->query('INSERT INTO shop_mail_settings (id,settings_json) VALUES (1,%s) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json),updated_at=UTC_TIMESTAMP()', self::json($settings));
            }
            $this->db->commit();
            return $plan['counts'];
        } catch (\Throwable $error) { $this->db->rollback(); throw $error; }
    }

    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) return $value;
            if (array_keys($value) !== range(0, count($value) - 1)) ksort($value);
            return array_map($sort, $value);
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    private static function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) throw new InvalidArgumentException('Záloha obsahuje neznámá pole. Použij export z této verze obchodu.');
    }
    private static function text(mixed $value, int $max): void
    {
        if (!is_string($value) || trim($value) === '' || preg_match('/^.{1,' . $max . '}$/uD', $value) !== 1 || preg_match('/\p{C}/u', $value)) throw new InvalidArgumentException('Zkontroluj názvy a jejich délku v záloze.');
    }
    private static function order(mixed $value): void
    {
        if (!is_int($value) || $value < 0 || $value > 65535) throw new InvalidArgumentException('Pořadí v záloze musí být celé číslo 0 až 65535.');
    }
    public static function slotLabel(string $slot): string
    {
        return ['primary' => 'Hlavní menu', 'category_tabs' => 'Podkategorie', 'utility' => 'Horní odkazy', 'footer' => 'Patička'][$slot] ?? $slot;
    }
    public static function siteLabel(string $key): string
    {
        return ['hero_title' => 'Hlavička: nadpis', 'hero_subtitle' => 'Hlavička: podnadpis', 'catalog_home_title' => 'Úvod: nadpis výběru',
            'catalog_title' => 'Katalog: nadpis', 'catalog_home_intro' => 'Úvod: text výběru', 'catalog_all_intro' => 'Katalog: úvod',
            'catalog_category_intro' => 'Kategorie: úvod', 'footer_intro' => 'Patička: úvod', 'footer_closing' => 'Patička: závěr'][$key] ?? $key;
    }

    public static function describe(string $type, mixed $value): string
    {
        if ($value === null) return 'Dosud neexistuje.';
        if (is_string($value)) return $value;
        if ($type === 'template') return 'Předmět: ' . $value['subject'] . "\n\n" . $value['message'];
        if ($type === 'category') return $value['title'] . "\nPořadí: " . $value['sort_order'] . "\nViditelnost: " . ($value['enabled'] ? 'zapnuto' : 'skryto');
        if ($type === 'page') return 'V automatickém menu: ' . ($value['visible'] ? 'ano' : 'ne') . "\nPořadí: " . $value['sort_order'];
        $source = ['categories' => 'Kategorie', 'content' => 'Stránky', 'manual' => 'Vlastní odkazy'][$value['source']];
        $lines = ['Zdroj: ' . $source, 'Nadpis: ' . ($value['title'] ?? 'Informace')];
        if ($value['source'] === 'categories') $lines[] = 'Kategorie: ' . (($value['parent'] ?? '') === '@context' ? 'podle otevřené kategorie' : (($value['parent'] ?? '') ?: 'hlavní kategorie'));
        if ($value['source'] === 'content') $lines[] = 'Přidat Blog: ' . (!empty($value['include_blog']) ? 'ano' : 'ne');
        $items = array_column($value['items'], null, 'id');
        foreach ($value['items'] as $item) {
            $lines[] = $item['label'] . ' → ' . ($item['target'] ?: 'Úvodní stránka') . ' · pořadí ' . ($item['sort_order'] ?? 0) .
                (empty($item['parent_id']) ? '' : ' · pod ' . ($items[$item['parent_id']]['label'] ?? $item['parent_id']));
        }
        if ($items === []) $lines[] = 'Žádné vlastní odkazy.';
        return implode("\n", $lines);
    }
}
