<?php
declare(strict_types=1);

use SimpleStore\Database\SchemaUpdater;

// admin.php has already authenticated the administrator and verified POST CSRF.
$screen = 'database';
$databaseError = '';
$databaseStatus = null;
$updater = new SchemaUpdater($db, __DIR__ . '/../../database/schema.sql');
try {
    if ($method === 'POST' && ($_POST['action'] ?? '') === 'schema-apply') {
        $changed = $updater->apply();
        header('Location: ' . $adminUrl . '?section=database&updated=' . ($changed ? '1' : '0'), true, 303);
        exit;
    }
    $databaseStatus = $updater->status();
} catch (Throwable $exception) {
    http_response_code(500);
    $databaseError = $exception->getMessage();
    try {
        $databaseStatus = $updater->status();
    } catch (Throwable $statusError) {
        error_log((string) $statusError);
    }
}
