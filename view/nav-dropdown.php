<?php
$escapeNav = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$drawNavChildren = static function (array $children) use (&$drawNavChildren, $escapeNav): void {
    echo '<ul class="nav-dropdown-list">';
    foreach ($children as $child) {
        echo '<li><a href="' . $escapeNav($child['href']) . '"' .
            (!empty($child['newTab']) ? ' target="_blank" rel="noopener noreferrer"' : '') .
            '>' . $escapeNav($child['label']) . '</a>';
        if ($child['children'] !== []) $drawNavChildren($child['children']);
        echo '</li>';
    }
    echo '</ul>';
};
?>
<details class="nav-dropdown<?= $link['active'] ? ' active' : '' ?>">
  <summary><?= $escapeNav($link['label']) ?> <span aria-hidden="true">⌄</span></summary>
  <div class="nav-dropdown-panel">
    <a class="nav-dropdown-all" href="<?= $escapeNav($link['href']) ?>"<?= !empty($link['newTab']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>Zobrazit vše: <?= $escapeNav($link['label']) ?></a>
    <?php $drawNavChildren($link['children']); ?>
  </div>
</details>
