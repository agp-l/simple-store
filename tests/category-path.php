<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryPath;

require dirname(__DIR__) . '/src/Category/CategoryPath.php';

function checkPath(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

checkPath(CategoryPath::valid('obleceni/muzi/bundy'), 'Nested path');
checkPath(!CategoryPath::valid('obleceni//bundy'), 'Empty segment');
checkPath(CategoryPath::parent('obleceni/muzi/bundy') === 'obleceni/muzi', 'Immediate parent');
checkPath(CategoryPath::contains('obleceni/muzi', 'obleceni/muzi/bundy'), 'Child product');
checkPath(!CategoryPath::contains('spani/spacaky', 'spani/spacaky-ostatni'), 'Whole segments only');
checkPath(CategoryPath::fromProduct(['category' => 'spacaky', 'subcategory' => '']) === 'spani/spacaky', 'Old sleeping bag');
checkPath(CategoryPath::fromProduct(['category' => 'batohy', 'subcategory' => 'do-25']) === 'batohy/batohy-do-25-l', 'Old backpack');
checkPath(CategoryPath::forStorage('obleceni/zeny/bundy') === ['obleceni', 'zeny/bundy'], 'Store nested path');

echo "Category path tests passed.\n";
