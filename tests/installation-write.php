<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Configuration\{Installation, InstallationSettings};

function verifyInstallationWrite(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}
$directory = sys_get_temp_dir() . '/installation-write-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$file = $directory . '/installation.json';
$originalEnvironment = getenv('SIMPLE_STORE_INSTALLATION_FILE');
try {
    file_put_contents($file, json_encode(['public_base_url' => 'https://old.example.test', 'previous_base_urls' => ['https://first.example.test/shop']]));
    chmod($file, 0640);
    putenv('SIMPLE_STORE_INSTALLATION_FILE=' . $file);
    $store = new InstallationSettings();
    $revision = InstallationSettings::revision(Installation::current());
    $store->save(' https://NEW.example.test/eshop/ ', $revision);
    $saved = Installation::current();
    verifyInstallationWrite($saved->baseUrl() === 'https://new.example.test/eshop', 'The running application did not adopt the saved address.');
    verifyInstallationWrite($saved->previousBaseUrls() === ['https://first.example.test/shop', 'https://old.example.test'], 'Previous origins were not retained automatically.');
    verifyInstallationWrite($saved->relocate('https://old.example.test/cs/produkt/test?variant=blue#photo') === 'https://new.example.test/eshop/cs/produkt/test?variant=blue#photo', 'Old product links did not follow the new address.');
    verifyInstallationWrite($saved->url('account/?google=callback') === 'https://new.example.test/eshop/account/?google=callback', 'Google address did not change.');
    foreach (['comgate', 'gopay', 'btcpay'] as $gateway) {
        verifyInstallationWrite($saved->checkout([])[$gateway]['return_base_url'] === $saved->baseUrl(), 'A payment address remained stale.');
    }
    verifyInstallationWrite((fileperms($file) & 0777) === 0640, 'Saving changed file permissions.');
    $bytes = file_get_contents($file);
    // A second administrator's old form cannot overwrite the newly saved address.
    try { $store->save('https://stale.example.test', $revision); throw new LogicException('Stale form accepted.'); }
    catch (InvalidArgumentException $expected) {}
    verifyInstallationWrite(file_get_contents($file) === $bytes, 'A stale form changed the file.');
    $revision = InstallationSettings::revision($saved);
    foreach (['https://example.test?key=1', 'http://public.example.test', 'https://user:secret@example.test', '', "https://example.test/a\nb"] as $invalid) {
        try { $store->save($invalid, $revision); throw new LogicException('Invalid URL accepted.'); }
        catch (InvalidArgumentException $expected) {}
        verifyInstallationWrite(file_get_contents($file) === $bytes, 'Invalid input changed the file.');
    }
    $lock = fopen($directory . '/.installation.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        try { $store->save('https://locked.example.test', $revision); throw new LogicException('Concurrent save ignored its lock.'); }
        catch (RuntimeException $expected) {}
        verifyInstallationWrite(file_get_contents($file) === $bytes, 'A locked save changed the file.');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    // Saving the same value performs the real write without adding duplicate history.
    $store->save($saved->baseUrl(), $revision);
    verifyInstallationWrite(file_get_contents($file) === $bytes, 'An unchanged address altered configuration history.');
    $next = $store->save('https://another.example.test', InstallationSettings::revision(Installation::current()));
    verifyInstallationWrite(in_array('https://new.example.test/eshop', $next->previousBaseUrls(), true), 'A later move lost the preceding subdirectory.');
    verifyInstallationWrite(glob($directory . '/.installation-*.tmp') === [], 'Temporary config files were left behind.');
    $history = array_map(static fn (int $n): string => 'https://old' . $n . '.example.test', range(1, 32));
    file_put_contents($file, json_encode(['public_base_url' => 'https://current.example.test', 'previous_base_urls' => $history]));
    $before = file_get_contents($file);
    try { $store->save('https://next.example.test', InstallationSettings::revision(Installation::current())); throw new LogicException('Full history was silently truncated.'); }
    catch (InvalidArgumentException $expected) {}
    verifyInstallationWrite(file_get_contents($file) === $before, 'A full history corrupted the configuration.');
} finally {
    putenv($originalEnvironment === false ? 'SIMPLE_STORE_INSTALLATION_FILE' : 'SIMPLE_STORE_INSTALLATION_FILE=' . $originalEnvironment);
    foreach (glob($directory . '/{*,.*}', GLOB_BRACE) ?: [] as $entry) if (is_file($entry)) unlink($entry);
    rmdir($directory);
}
echo "Single-address saving, old links, stale forms, lock contention and permissions OK\n";
