<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Navigation\MenuDefinitionRepository;

// admin.php has already checked the session and CSRF token.
$screen = 'menus';
$language = $_POST['language'] ?? $_GET['language'] ?? $site['default_language'];
if (!is_string($language) || !in_array($language, $site['languages'], true)) {
    throw new InvalidArgumentException('Neplatný jazyk menu.');
}
$categories = new CategoryRepository($db);
$definitions = new MenuDefinitionRepository($db, require __DIR__ . '/../../config/menus.php', $categories);
$menuReady = $definitions->installed();
$menuError = '';
$slot = $_POST['slot'] ?? $_GET['slot'] ?? 'primary';
if (!is_string($slot) || preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $slot) !== 1) {
    throw new InvalidArgumentException('Neplatné umístění menu.');
}

if ($method === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'page-menu') {
            $key = $_POST['key'] ?? null;
            $expected = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
            $order = filter_var($_POST['menu_order'] ?? null, FILTER_VALIDATE_INT);
            if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
                $expected === false || $expected < 1 || $order === false) {
                throw new InvalidArgumentException('Neplatná stránka nebo pořadí.');
            }
            $content->saveMenuPosition($key, $language, $expected, isset($_POST['visible_in_menu']), $order);
        } elseif ($action === 'menu-slot') {
            if (!$menuReady) throw new InvalidArgumentException('Nejdřív importuj database/schema.sql.');
            $source = $_POST['source'] ?? null;
            $parent = $_POST['parent_path'] ?? null;
            if (!is_string($source) || !is_string($parent)) {
                throw new InvalidArgumentException('Neplatné nastavení menu.');
            }
            $title = $_POST['title'] ?? null;
            if ($title !== null && !is_string($title)) throw new InvalidArgumentException('Neplatný nadpis menu.');
            $definitions->saveSlot($language, $slot, $source, $parent, isset($_POST['include_blog']), $title);
        } elseif ($action === 'menu-item-save') {
            if (!$menuReady) throw new InvalidArgumentException('Nejdřív importuj database/schema.sql.');
            $id = $_POST['id'] ?? '';
            if (!is_string($id) || ($id !== '' && preg_match('/^[a-f0-9]{16}$/D', $id) !== 1)) {
                throw new InvalidArgumentException('Neplatný odkaz menu.');
            }
            $input = $_POST;
            if (isset($_POST['destination_type'])) {
                $choice = $_POST['destination_type'];
                if (!is_string($choice) || !in_array($choice,
                    ['page', 'category', 'home', 'blog', 'custom', 'external'], true)) {
                    throw new InvalidArgumentException('Vyber, kam má odkaz vést.');
                }
                $field = ['page' => 'destination_page', 'category' => 'destination_category',
                    'custom' => 'destination_custom', 'external' => 'destination_external'][$choice] ?? null;
                $target = $choice === 'home' ? '' : ($choice === 'blog' ? 'blog' : $_POST[$field] ?? null);
                if (!is_string($target)) throw new InvalidArgumentException('Vyber cíl odkazu.');
                $input['target_type'] = $choice === 'category' ? 'category' :
                    ($choice === 'external' ? 'external' : 'path');
                $input['target'] = $target;
                if ($choice === 'page') {
                    $validPages = array_column($content->pagesForMenu($language), 'slug');
                    if (!in_array($target, $validPages, true)) {
                        throw new InvalidArgumentException('Vyber existující stránku.');
                    }
                }
            }
            $definitions->saveItem($language, $slot, $id === '' ? null : $id, $input);
        } elseif ($action === 'menu-item-remove') {
            if (!$menuReady) throw new InvalidArgumentException('Nejdřív importuj database/schema.sql.');
            $id = $_POST['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-f0-9]{16}$/D', $id) !== 1) {
                throw new InvalidArgumentException('Neplatný odkaz menu.');
            }
            $definitions->removeItem($language, $slot, $id);
        }
        header('Location: ' . $adminUrl . '?' . http_build_query([
            'section' => 'menus', 'language' => $language, 'slot' => $slot, 'saved' => 1,
        ]), true, 303);
        exit;
    } catch (InvalidArgumentException|RuntimeException $exception) {
        $menuError = $exception->getMessage();
    }
}

$menuSlots = $definitions->settings($language);
if (!isset($menuSlots[$slot])) {
    $slot = 'primary';
}
$activeSlot = $menuSlots[$slot];
$menuItems = $menuReady ? $definitions->itemsForAdmin($language, $slot) : [];
$editItem = $_GET['item'] ?? '';
$selectedItem = null;
if (is_string($editItem)) {
    foreach ($menuItems as $item) {
        if ($item['id'] === $editItem) $selectedItem = $item;
    }
}
$categoryOptions = $categories->installed() ? $categories->allForAdmin($language) : [];
$pageRows = $content->pagesForMenu($language);
