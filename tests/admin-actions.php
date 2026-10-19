<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Admin\AdminActionPolicy;

$views = new AppendIterator();
foreach (['admin', 'manage'] as $directory) {
    $views->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        dirname(__DIR__) . '/view/' . $directory, FilesystemIterator::SKIP_DOTS)));
}
$guestActions = ['login', 'logout', 'reset-request', 'reset-complete', 'visitor-preview'];
$checked = [];

foreach ($views as $view) {
    if (!$view->isFile() || $view->getExtension() !== 'php') {
        continue;
    }
    preg_match_all('/name="action" value="([a-z][a-z0-9-]+)"/',
        (string) file_get_contents($view->getPathname()), $matches);
    foreach ($matches[1] as $action) {
        if (in_array($action, $guestActions, true)) {
            continue;
        }
        if (!AdminActionPolicy::allows($action)) {
            throw new RuntimeException($view->getFilename() . ': admin form action ' .
                $action . ' would be rejected before reaching its handler.');
        }
        if (str_contains($view->getPathname(), '/orders/') &&
            !in_array($action, AdminActionPolicy::ORDERS, true) &&
            !in_array($action, AdminActionPolicy::ACCOUNTING, true)) {
            throw new RuntimeException('Order form action ' . $action . ' has no order or accounting route.');
        }
        $checked[$action] = true;
    }
}

if (!isset($checked['fio-sync'], $checked['change-order-shipping'], $checked['product-sale'])) {
    throw new RuntimeException('The Fio sync, shipping and product sale forms must remain available to administrators.');
}

echo 'Admin form actions: ' . count($checked) . " verified\n";
