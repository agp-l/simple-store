<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\LegacyTablePrefixMigration;
use SimpleStore\Database\SchemaUpdater;

$config = ['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306];
$server = ConnectionFactory::create($config);
$database = 'shop_prefix_test_' . bin2hex(random_bytes(4));
$server->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $db = ConnectionFactory::create(array_replace($config, ['database' => $database]));
    (new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
    $migration = new LegacyTablePrefixMigration($db);
    if ($migration->status()['state'] !== 'ready') throw new RuntimeException('Legacy schema is not ready.');

    $db->query('CREATE TABLE shop_users (id BIGINT PRIMARY KEY)');
    if ($migration->status()['state'] !== 'conflict') throw new RuntimeException('Name collision was ignored.');
    try {
        $migration->apply();
        throw new RuntimeException('Migration overwrote a conflicting table.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'kolidují')) throw $error;
    }
    $db->query('DROP TABLE shop_users');

    $key = bin2hex(random_bytes(16));
    $db->insert('product_revisions', [
        'product_key' => $key, 'active_product_key' => $key, 'language' => 'cs',
        'revision_number' => 1, 'slug' => 'prefix-' . $key, 'active_slug' => 'prefix-' . $key,
        'name' => 'Preserved product', 'category' => 'batohy', 'price_czk' => 120,
        'description' => 'Existing product data', 'image_path' => '', 'published' => 1,
    ]);
    $db->insert('users', ['username' => 'prefix_owner', 'email' => 'prefix@example.test',
        'password_hash' => 'test-only-hash', 'role' => 'admin']);
    $userId = (int) $db->queryFirstField('SELECT id FROM users WHERE username=%s', 'prefix_owner');
    $db->insert('shop_password_resets', ['token_hash' => str_repeat('a', 64), 'user_id' => $userId,
        'password_hash_at_issue' => str_repeat('b', 64), 'role' => 'admin',
        'expires_at' => '2030-01-01 00:00:00']);

    if (!$migration->apply() || $migration->status()['state'] !== 'migrated' || $migration->apply()) {
        throw new RuntimeException('Migration was not repeatable.');
    }
    foreach (LegacyTablePrefixMigration::TABLES as $old => $new) {
        $row = $migration->status()['tables'][$old];
        if ($row !== ['old' => 'VIEW', 'new' => 'BASE TABLE']) {
            throw new RuntimeException('Incorrect source/target after renaming ' . $old);
        }
    }
    if ($db->queryFirstField('SELECT name FROM shop_product_revisions WHERE product_key=%s', $key)
        !== 'Preserved product' || $db->queryFirstField('SELECT name FROM product_revisions WHERE product_key=%s', $key)
        !== 'Preserved product') {
        throw new RuntimeException('Product content disappeared.');
    }
    // Current application code must still be able to update, insert and lock old names.
    $db->query('UPDATE product_revisions SET name=%s WHERE product_key=%s', 'Updated product', $key);
    if ($db->queryFirstField('SELECT name FROM shop_product_revisions WHERE product_key=%s', $key)
        !== 'Updated product') throw new RuntimeException('Legacy writes are not passed through.');
    $db->startTransaction();
    try {
        $db->queryFirstRow('SELECT * FROM product_revisions WHERE product_key=%s FOR UPDATE', $key);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
    $db->insert('users', ['username' => 'prefix_customer', 'email' => 'another@example.test',
        'password_hash' => 'test-only-hash', 'role' => 'customer']);
    if ((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_users WHERE username=%s', 'prefix_customer') !== 1) {
        throw new RuntimeException('Legacy insert was not passed through.');
    }
    $db->query('DELETE FROM shop_users WHERE id=%i', $userId);
    if ((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_password_resets WHERE user_id=%i', $userId) !== 0) {
        throw new RuntimeException('Foreign key did not follow renamed users table.');
    }
    echo "Legacy table prefix migration preserves products, writes and references.\n";
} finally {
    $server->query('DROP DATABASE `' . $database . '`');
}
