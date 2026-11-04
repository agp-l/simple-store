<?php
declare(strict_types=1);

// Reached after the administrator session check; this screen has no POST actions.
$screen = 'dashboard';
$dashboard = null;
$dashboardError = '';
$dashboardScope = ($_GET['scope'] ?? '') === 'all' ? 'all' : 'published';
try {
    $dashboard = (new \SimpleStore\Admin\StoreDashboard($db))->load($site['default_language'], $dashboardScope === 'all');
} catch (RuntimeException $exception) {
    error_log((string) $exception);
    $dashboardError = 'Přehled se nepodařilo načíst. Zkontroluj stav databáze v sekci Databáze.';
}
