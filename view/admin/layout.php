<?php
declare(strict_types=1);

// Choose text and content only; the chrome is shared with customer pages.
$panelTitle = 'Administrace · dobrodruzi';
$panelDescription = 'Správa obchodu Dobrodruzi';
$panelHeroTitle = in_array($screen, ['editor', 'products', 'categories', 'menus', 'media', 'orders', 'settings', 'users', 'database'], true)
    ? 'Správa obchodu' : 'Vstup do administrace';
$panelHeroSubtitle = 'Obsah, produkty a navigace na jednom místě.';
$bodyView = __DIR__ . '/panel.php';
$mediaManager = $screen === 'media';
require __DIR__ . '/../panel/layout.php';
