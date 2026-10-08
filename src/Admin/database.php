<?php
declare(strict_types=1);

use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Database\LegacyTablePrefixMigration;

// admin.php has already authenticated the administrator and verified POST CSRF.
$screen = 'database';
$databaseError = '';
$databaseStatus = null;
$tablePrefixStatus = null;
$updater = new SchemaUpdater($db, __DIR__ . '/../../database/schema.sql');
$tablePrefixMigration = new LegacyTablePrefixMigration($db);
try {
    if ($method === 'POST' && ($_POST['action'] ?? '') === 'table-prefix-migrate') {
        $changed = $tablePrefixMigration->apply();
        header('Location: ' . $adminUrl . '?section=database&prefix_updated=' . ($changed ? '1' : '0'), true, 303);
        exit;
    }
    if ($method === 'POST' && ($_POST['action'] ?? '') === 'table-prefix-cleanup') {
        $changed = $tablePrefixMigration->removeLegacyViews();
        header('Location: ' . $adminUrl . '?section=database&prefix_cleaned=' . ($changed ? '1' : '0'), true, 303);
        exit;
    }
    if ($method === 'POST' && ($_POST['action'] ?? '') === 'schema-apply') {
        $changed = $updater->apply();
        header('Location: ' . $adminUrl . '?section=database&updated=' . ($changed ? '1' : '0'), true, 303);
        exit;
    }
    $databaseStatus = $updater->status();
    $tablePrefixStatus = $tablePrefixMigration->status();
} catch (Throwable $exception) {
    http_response_code(500);
    $databaseError = $exception->getMessage();
    try {
        $databaseStatus = $updater->status();
        $tablePrefixStatus = $tablePrefixMigration->status();
    } catch (Throwable $statusError) {
        error_log((string) $statusError);
    }
}
