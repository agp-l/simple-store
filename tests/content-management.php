<?php
declare(strict_types=1);

// Check the bounded administrative queries without a running MySQL server.
class MeekroDB
{
    public string $sql = '';
    public array $parameters = [];
    public array $rows = [];

    public function query(string $sql, mixed ...$parameters): array
    {
        $this->sql = $sql;
        $this->parameters = $parameters;
        return $this->rows;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Content\ContentRepository;

$db = new MeekroDB();
$content = new ContentRepository($db, ['cs', 'en']);
$db->rows = array_fill(0, 25, ['document_key' => str_repeat('a', 32),
    'language' => 'cs', 'type' => 'page', 'title' => 'Cesta']);
$page = $content->managementPage('cs', 'page', false, ' Cesta ', 24);
if (count($page['items']) !== 24 || $page['nextOffset'] !== 48 ||
    !str_contains($db->sql, 'active_document_key IS NOT NULL AND language=%s AND type=%s AND published=%i') ||
    !str_contains($db->sql, '(LOCATE(%s, title)>0 OR LOCATE(%s, slug)>0)') ||
    !str_contains($db->sql, 'ORDER BY id DESC LIMIT %i OFFSET %i') ||
    $db->parameters !== ['cs', 'page', 0, 'Cesta', 'Cesta', 25, 24]) {
    throw new RuntimeException('The content index must filter and paginate active drafts in SQL.');
}

$db->rows = [['document_key' => str_repeat('a', 32), 'language' => 'cs'],
    ['document_key' => str_repeat('a', 32), 'language' => 'en']];
$translations = $content->translationLanguages([str_repeat('a', 32), str_repeat('a', 32)]);
if ($db->parameters !== [str_repeat('a', 32)] ||
    $translations !== [str_repeat('a', 32) => ['cs', 'en']]) {
    throw new RuntimeException('Translations must be checked only for displayed document keys.');
}

foreach ([['de', null, null, '', 0], [null, 'other', null, '', 0],
    [null, null, null, '', -1], [null, null, null, str_repeat('x', 201), 0]] as $invalid) {
    try {
        $content->managementPage(...$invalid);
        throw new RuntimeException('An invalid management filter was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
}

echo "Content management query tests passed.\n";
