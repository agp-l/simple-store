<?php
declare(strict_types=1);

// Reproduce the upgrade from an existing shop: the old NOT NULL links and
// foreign-key names must survive a restart after a partial schema update.
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

$connection = [
    'host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
];
// Keep this upgrade fixture separate from other integration tests, which may
// already contain invoices without an order on their current schema.
$db = ConnectionFactory::create($connection);
$db->query('CREATE DATABASE IF NOT EXISTS simple_store_legacy CHARACTER SET utf8mb4');
$connection['database'] = 'simple_store_legacy';
$db = ConnectionFactory::create($connection);
$updater = new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql');
$updater->apply();

foreach (['shop_comgate_payments' => 'comgate_order_fk',
    'shop_gopay_payments' => 'gopay_order_fk',
    'shop_invoices' => 'invoice_order_fk'] as $table => $oldConstraint) {
    // Each operation is separate so the fixture itself also works on MariaDB.
    $db->query('ALTER TABLE ' . $table . ' DROP FOREIGN KEY ' . $oldConstraint);
    $db->query('ALTER TABLE ' . $table . ' MODIFY COLUMN order_id BIGINT UNSIGNED NOT NULL');
    $db->query('ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $oldConstraint .
        ' FOREIGN KEY (order_id) REFERENCES shop_orders(id)');
}

// An interrupted run leaves the schema tracker on an older/failed version.
$db->query('UPDATE shop_schema_updates SET schema_hash=%s, state=%s WHERE id=%i',
    str_repeat('0', 64), 'failed', 1);
if (!$updater->apply() || !$updater->status()['current']) {
    throw new RuntimeException('Retrying the existing installation did not finish the migration.');
}

foreach (['shop_comgate_payments' => 'comgate_detached_order_fk',
    'shop_gopay_payments' => 'gopay_detached_order_fk',
    'shop_invoices' => 'invoice_detached_order_fk'] as $table => $newConstraint) {
    if ((int) $db->queryFirstField('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s AND IS_NULLABLE=%s',
        $table, 'order_id', 'YES') !== 1 ||
        (int) $db->queryFirstField('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=%s AND CONSTRAINT_NAME=%s
        AND DELETE_RULE=%s', $table, $newConstraint, 'SET NULL') !== 1) {
        throw new RuntimeException('Order link migration failed for ' . $table);
    }
}

echo "Existing order/payment migration passed.\n";
