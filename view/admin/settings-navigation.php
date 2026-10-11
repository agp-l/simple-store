<?php
declare(strict_types=1);
$settingsLinks = ['overview' => 'Přehled', 'appearance' => 'Texty webu', 'delivery' => 'Doprava',
    'carriers' => 'Dopravci', 'payment' => 'Platby', 'prices' => 'Ceny',
    'mail' => 'E-maily', 'legal' => 'Podmínky'];
?>
<nav class="panel-settings-nav" aria-label="Oblasti nastavení obchodu">
  <?php foreach ($settingsLinks as $key => $label): ?>
    <a href="<?= $escape($adminUrl . '?section=settings&tab=' . $key) ?>" <?= $settingsTab === $key ? 'aria-current="page"' : '' ?>><?= \SimpleStore\Rendering\Icon::render(['overview' => 'list', 'appearance' => 'edit', 'delivery' => 'package', 'carriers' => 'package', 'payment' => 'card', 'prices' => 'card', 'mail' => 'mail', 'legal' => 'document'][$key]) ?> <?= $escape($label) ?></a>
  <?php endforeach; ?>
</nav>
