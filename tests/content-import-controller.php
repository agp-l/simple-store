<?php
declare(strict_types=1);

// Preview reads only; any attempted write must fail this test.
class MeekroDB
{
    public ?array $document = null;
    public ?array $duplicate = null;
    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        return str_contains($sql, 'document_key=%s') ? $this->document : $this->duplicate;
    }
}
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Content\ContentImport;
use SimpleStore\Content\ContentRepository;

$db = new MeekroDB();
$content = new ContentRepository($db);
$site = ['default_language' => 'cs', 'languages' => ['cs']];
$basePath = '/shop/'; $adminUrl = '/shop/admin.php'; $csrf = 'test-csrf';
$run = static function (array $post, array $files = []) use ($site, $content, $basePath, $adminUrl, $csrf): array {
    $_POST = $post; $_FILES = $files; $_GET = [];
    $method = 'POST'; $action = $post['action'];
    http_response_code(200);
    require dirname(__DIR__) . '/src/Admin/content-import.php';
    return [$importPreview, $importError, $importJson, $importToken, http_response_code()];
};
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$json = json_encode(['format' => ContentImport::FORMAT, 'type' => 'page', 'language' => 'cs',
    'content' => ['title' => 'Importovaný dokument', 'body' => 'Text']]);
$post = ['action' => 'content-import-preview', 'mode' => 'new', 'json' => $json];
[$preview, $error, $retained, $token] = $run($post);
$check($error === '' && $preview['slug'] === 'importovany-dokument' && $retained === $json && $token !== '', 'Preview did not retain the validated input.');
$pretty = json_encode(json_decode($json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
[, $lfError, , $lfToken] = $run(array_replace($post, ['json'=>$pretty]));
[, $crlfError, $normalized, $crlfToken] = $run(array_replace($post, ['json'=>str_replace("\n", "\r\n", $pretty)]));
$check($lfError === '' && $crlfError === '' && $lfToken === $crlfToken && $normalized === $pretty,
    'A file preview must survive browser textarea line-ending conversion without a second preview.');
foreach ([['action' => 'content-import-save'], ['action' => 'content-import-save', 'preview_token' => 'wrong'],
    ['action' => 'content-import-save', 'preview_token' => $token, 'json' => str_replace('Text', 'Changed', $json)],
    ['json' => '{'], ['mode' => []]] as $override) {
    [$preview, $error, $retained, , $status] = $run(array_replace($post, $override));
    $check($preview === null && $error !== '' && $status === 422 && $retained === ($override['json'] ?? $json), 'Invalid or unpreviewed data was not rejected with its input retained.');
}
foreach ([['error' => UPLOAD_ERR_INI_SIZE], ['error' => UPLOAD_ERR_PARTIAL],
    ['error' => UPLOAD_ERR_OK, 'name' => 'file.json', 'tmp_name' => '/etc/passwd', 'size' => 10],
    ['error' => []]] as $upload) {
    [$preview, $error, $retained] = $run($post, ['json_file' => $upload]);
    $check($preview === null && $error !== '' && $retained === $json, 'Invalid upload was accepted or erased pasted JSON.');
}
[$preview, $error] = $run($post, ['json_file' => ['error' => UPLOAD_ERR_NO_FILE]]);
$check($preview !== null && $error === '', 'Pasted JSON must work without an uploaded file.');
$db->duplicate = ['document_key' => str_repeat('b', 32)];
[$preview, $error] = $run($post);
$check($preview === null && str_contains($error, 'adresa už existuje'), 'Duplicate slug must be rejected before saving.');
$db->duplicate = null;
$data = ContentImport::decode($json);
$db->document = ContentImport::fields($data, ['cs'], 'new') + ['document_key' => str_repeat('a', 32), 'revision_number' => 2];
$post['mode'] = 'update'; $post['json'] = ContentImport::export($db->document);
[$preview, $error] = $run($post);
$check($preview !== null && $error === '', 'Current revision could not be previewed.');
$db->document['revision_number'] = 3;
[$preview, $error] = $run($post);
$check($preview === null && str_contains($error, 'změnil'), 'A stale export must not reach saving.');
echo "Content import controller: preview, stale revision, duplicate, upload errors and changed-payload rejection passed.\n";
