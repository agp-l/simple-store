<?php
declare(strict_types=1);

// The key is a placement in a view, not a database table or a CSS selector.
// New menus can read category children, published pages, or nested manual items.
return [
    'primary' => ['source' => 'categories', 'parent' => ''],
    'category_tabs' => ['source' => 'categories', 'parent' => '@context'],
    'utility' => ['source' => 'content', 'include_blog' => true],
    'footer' => ['source' => 'manual', 'title' => 'Informace', 'items' => [
        ['label' => 'Doprava a platba', 'path' => 'doprava-a-platba', 'children' => []],
        ['label' => 'Výměna a vrácení zboží', 'path' => 'vymena-a-vraceni-zbozi', 'children' => []],
        ['label' => 'Obchodní podmínky', 'path' => 'obchodni-podminky', 'children' => []],
        ['label' => 'Reklamační řád', 'path' => 'reklamacni-rad', 'children' => []],
        ['label' => 'Ochrana osobních údajů', 'path' => 'ochrana-osobnich-udaju', 'children' => []],
        ['label' => 'Kontakt', 'path' => 'kontakt', 'children' => []],
    ]],
];
