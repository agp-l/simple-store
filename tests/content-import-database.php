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
    $beforeTopics = $repository->publishedTopicCounts('cs');
    $testPosts = [];
    for ($i = 0; $i < 7; $i++) {
        $fields = ['type'=>'post','language'=>'cs','title'=>'Kategorie '.$i, 'slug'=>'topic-'.bin2hex(random_bytes(8)),
            'summary'=>'Zkouška kategorie', 'published'=>true,'visible_in_menu'=>false,'menu_order'=>0,
            'body'=>ContentBody::encode([['type'=>'text','heading'=>'Zkouška','body'=>'Text']], null, 'spani-venku')];
        $saved = $repository->saveRevision($fields); $keys[] = $saved['document_key']; $testPosts[] = $saved['document_key'];
        if ($i === 0) {
            $fields['published'] = false;
            $repository->saveRevision($fields, $saved['document_key'], 1);
        }
    }
    $counts = $repository->publishedTopicCounts('cs');
    $check(($counts['spani-venku'] ?? 0) === ($beforeTopics['spani-venku'] ?? 0) + 6, 'Topic counts leaked an inactive revision or draft.');
    $first = $repository->publishedPostsPage('cs', 0, 2, 'spani-venku');
    $second = $repository->publishedPostsPage('cs', 2, 2, 'spani-venku');
    $check(count($first['items']) === 2 && $first['nextOffset'] === 2 && $second['nextOffset'] === 4 &&
        array_intersect(array_column($first['items'],'document_key'), array_column($second['items'],'document_key')) === [], 'Topic pagination repeated or skipped its page boundary.');
    $check(!in_array($testPosts[0], array_column($repository->publishedPostsPage('cs',0,48,'spani-venku')['items'],'document_key'), true), 'Draft escaped into category.');
    $legacy = ['type'=>'post','language'=>'cs','title'=>'Starý text', 'slug'=>'legacy-'.bin2hex(random_bytes(8)),
        'summary'=>'','body'=>'Plain text {not valid JSON}', 'published'=>true,'visible_in_menu'=>false,'menu_order'=>0];
    $saved = $repository->saveRevision($legacy); $keys[]=$saved['document_key'];
    $check(in_array($saved['document_key'], array_column($repository->publishedPostsPage('cs',0,48,'zapisnik')['items'],'document_key'),true), 'Legacy text disappeared or broke JSON category filtering.');
} finally {
    foreach ($keys as $key) $db->query('DELETE FROM shop_content_revisions WHERE document_key=%s', $key);
}
echo "Content JSON database: private creation, publication, history, translations, concurrency and copies passed.\n";
