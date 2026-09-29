<?php
declare(strict_types=1);

$panelTitle = 'Můj účet · dobrodruzi';
$panelDescription = 'Zákaznický účet obchodu Dobrodruzi';
$panelHeroTitle = $screen === 'account' ? 'Moje cesty a objednávky' : 'Vítejte u dobrodruhů';
$panelHeroSubtitle = $screen === 'account'
    ? 'Vše pro další výpravu najdete na jednom místě.'
    : 'Přihlaste se nebo si vytvořte účet pro další cestu.';
$bodyView = __DIR__ . '/panel.php';
require __DIR__ . '/../panel/layout.php';
