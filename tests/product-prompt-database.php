<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Product\ProductImportPromptRepository;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
$store = new ProductImportPromptRepository($db);
$original = $db->queryFirstRow('SELECT prompt_text FROM shop_product_import_settings WHERE id=%i', 1);
try {
    $db->query('DELETE FROM shop_product_import_settings WHERE id=%i', 1);
    if ($store->load() !== ProductImportPromptRepository::defaultText()) throw new RuntimeException('Prompt fallback is missing.');
    $custom = "Vlastní pravidla pro AI.\nZachovej JSON formát.\n</textarea><script>text only</script>\n";
    $store->save($custom);
    if ((new ProductImportPromptRepository($db))->load() !== $custom) throw new RuntimeException('Prompt was not persisted exactly.');
    foreach (['', str_repeat('x', ProductImportPromptRepository::LIMIT + 1), "bad\0text", "\xFF"] as $bad) {
        $rejected = false;
        try { $store->save($bad); } catch (InvalidArgumentException $expected) { $rejected = true; }
        if (!$rejected || $store->load() !== $custom) throw new RuntimeException('Invalid prompt replaced the saved text.');
    }
} finally {
    $db->query('DELETE FROM shop_product_import_settings WHERE id=%i', 1);
    if ($original !== null) $store->save($original['prompt_text']);
}
echo "AI prompt: database persistence, fallback and non-destructive validation passed.\n";
