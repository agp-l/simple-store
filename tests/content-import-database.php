<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Content\ContentBody;
use SimpleStore\Content\ContentImport;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$repository = new ContentRepository($db, ['cs', 'en']);
$keys = [];
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    foreach (['page', 'post'] as $type) {
        $data = ContentImport::decode(json_encode(['format' => ContentImport::FORMAT, 'type' => $type,
            'language' => 'cs', 'content' => ['title' => 'Příběh z hor', 'slug' => 'json-test-' . bin2hex(random_bytes(8)),
                'published' => true, 'body' => "První odstavec\n\nDruhý odstavec"]]));
        $fields = ContentImport::fields($data, ['cs', 'en'], 'new');
        $saved = $repository->saveRevision($fields); $key = $saved['document_key']; $keys[] = $key;
        $row = $repository->currentDocument($key, 'cs');
        $check($row !== null && !$row['published'] && $repository->findPublished($type, $row['slug'], 'cs') === null, 'New import leaked publicly.');
        $translation = array_replace($fields, ['language' => 'en', 'title' => 'English text']);
        $repository->saveRevision($translation, $key, 0);
        $export = ContentImport::decode(ContentImport::export($row));
        $export['content'] = ['title' => 'Upravený text', 'published' => true];
        $updatedFields = ContentImport::fields($export, ['cs', 'en'], 'update', $row);
        $repository->saveRevision($updatedFields, $key, $export['revision']);
        $updated = $repository->currentDocument($key, 'cs');
        $check((int) $updated['revision_number'] === 2 && $updated['body'] === $row['body'] &&
            $repository->findPublished($type, $row['slug'], 'cs')['title'] === 'Upravený text', 'Updating lost content, publication or revision history.');
        $check($repository->revision($key, 'cs', 1)['title'] === 'Příběh z hor' &&
            $repository->currentDocument($key, 'en')['title'] === 'English text', 'History or another translation changed.');
        $rejected = false;
        try { $repository->saveRevision($updatedFields, $key, 1); } catch (RuntimeException $expected) { $rejected = true; }
        $check($rejected && (int) $repository->currentDocument($key, 'cs')['revision_number'] === 2, 'A concurrent edit was overwritten.');
        $rejected = false;
        try { $repository->saveRevision($fields); } catch (InvalidArgumentException $expected) { $rejected = true; }
        $check($rejected, 'Duplicate import was saved.');
        $copy = ContentImport::decode(ContentImport::export($updated));
        $copy['content']['slug'] .= '-kopie';
        $new = $repository->saveRevision(ContentImport::fields($copy, ['cs', 'en'], 'new')); $keys[] = $new['document_key'];
        $check($new['document_key'] !== $key && !$repository->currentDocument($new['document_key'], 'cs')['published'], 'Copy reused the original identity or publication state.');
        $check(count(ContentBody::decode($updated['body'])) === 1, 'Text is no longer editable.');
    }
} finally {
    foreach ($keys as $key) $db->query('DELETE FROM shop_content_revisions WHERE document_key=%s', $key);
}
echo "Content JSON database: private creation, publication, history, translations, concurrency and copies passed.\n";
