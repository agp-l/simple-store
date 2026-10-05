<?php
declare(strict_types=1);
$settingsLinks = ['overview' => 'Přehled', 'appearance' => 'Texty webu', 'delivery' => 'Doprava',
    'carriers' => 'Dopravci', 'payment' => 'Platby', 'prices' => 'Ceny',
    'mail' => 'E-maily', 'legal' => 'Podmínky'];
?>
<nav class="panel-settings-nav" aria-label="Oblasti nastavení obchodu">
  <?php foreach ($settingsLinks as $key => $label): ?>
    <a href="<?= $escape($adminUrl . '?section=settings&tab=' . $key) ?>" <?= $settingsTab === $key ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a>
  <?php endforeach; ?>
</nav>
