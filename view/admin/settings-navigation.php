<?php
declare(strict_types=1);
$settingsLinks = ['overview' => 'Přehled', 'accounts' => 'Přihlášení', 'products' => 'Produkty', 'appearance' => 'Texty webu', 'delivery' => 'Doprava',
    'carriers' => 'Dopravci', 'payment' => 'Platby', 'prices' => 'Ceny',
    'mail' => 'E-maily', 'legal' => 'Podmínky'];
?>
<nav class="panel-settings-nav" aria-label="Oblasti nastavení obchodu">
  <?php foreach ($settingsLinks as $key => $label): ?>
    <a data-admin-setting="<?= $escape($key) ?>" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=' . $key) ?>" <?= $settingsTab === $key ? 'aria-current="page"' : '' ?>><span class="admin-tab-icon" aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render(['overview' => 'list', 'accounts' => 'user', 'products' => 'package', 'appearance' => 'edit', 'delivery' => 'truck', 'carriers' => 'package', 'payment' => 'card', 'prices' => 'card', 'mail' => 'mail', 'legal' => 'document'][$key]) ?></span> <?= $escape($label) ?></a>
  <?php endforeach; ?>
</nav>
