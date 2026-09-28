<?php
declare(strict_types=1);

// The key is a placement in a view, not a database table or a CSS selector.
// New menus can read category children, published pages, or nested manual items.
return [
    'primary' => ['source' => 'categories', 'parent' => ''],
    'category_tabs' => ['source' => 'categories', 'parent' => '@context'],
    'utility' => ['source' => 'content', 'include_blog' => true],
    'footer' => ['source' => 'categories', 'parent' => ''],
];
