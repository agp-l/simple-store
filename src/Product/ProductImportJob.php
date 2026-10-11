<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** A session-owned, private job checkpoint; one product per HTTP request, never replay an uncertain write. */
final class ProductImportJob
{
    private string $directory;
    public function __construct(string $token)
    {
        if (preg_match('/^[a-f0-9]{48}$/D', $token) !== 1) throw new InvalidArgumentException('Neplatný import.');
        $this->directory = sys_get_temp_dir() . '/simple-store-import-' . $token;
    }

    public static function upload(array $upload, string $mode = 'skip'): string
    {
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            throw new InvalidArgumentException('ZIP se nenahrál. Zkontroluj limity upload_max_filesize a post_max_size na hostingu.');
        }
        return self::fromFile($upload['tmp_name'], $mode);
    }

    /** Trusted local file entry point for CLI/tests; HTTP callers must use upload(). */
    public static function fromFile(string $path, string $mode = 'skip'): string
    {
        ProductZipConflictPolicy::validate($mode);
        $archive = new ProductZipArchive($path);
        $folders = $archive->products();
        $token = bin2hex(random_bytes(24));
        $job = new self($token);
        if (!mkdir($job->directory, 0700)) throw new RuntimeException('Nelze připravit dočasnou složku importu.');
        try {
            if (!copy($path, $job->directory . '/archive.zip')) throw new RuntimeException('ZIP nelze uložit.');
            chmod($job->directory . '/archive.zip', 0600);
            $job->write(['kind' => 'zip', 'duplicate_mode' => $mode, 'created' => time(), 'items' => array_map(static fn (string $folder): array =>
                ['folder' => $folder, 'status' => 'pending', 'message' => '', 'new_key' => bin2hex(random_bytes(16))], $folders)]);
        } catch (Throwable $error) { $job->remove(); throw $error; }
        return $token;
    }

    public static function fromItems(array $items): string
    {
        $token = bin2hex(random_bytes(24));
        $job = new self($token);
        if (!mkdir($job->directory, 0700)) throw new RuntimeException('Nelze připravit dočasnou složku importu.');
        try {
            foreach ($items as $index => &$item) {
                $payload = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $path = $job->directory . '/item-' . $index . '.json';
                if (file_put_contents($path, $payload) !== strlen($payload)) throw new RuntimeException('Nelze uložit připravený produkt.');
                chmod($path, 0600);
                $item = array_intersect_key($item, array_flip(['folder', 'status', 'message']));
                $item['payload'] = $index;
            }
            unset($item);
            $job->write(['kind' => 'json', 'created' => time(), 'items' => $items]);
        } catch (Throwable $error) { $job->remove(); throw $error; }
        return $token;
    }

    public function stepJson(ProductBulkTransfer $transfer): array
    {
        if (($this->state()['kind'] ?? 'zip') !== 'json') throw new InvalidArgumentException('Neplatný typ importu.');
        return $this->process(function (array $item) use ($transfer): array {
            $payload = json_decode((string) file_get_contents($this->directory . '/item-' . (int) $item['payload'] . '.json'), true, 40, JSON_THROW_ON_ERROR);
            return $transfer->import($payload);
        });
    }

    public function state(): array
    {
        if (!is_file($this->directory . '/state.json')) throw new InvalidArgumentException('Import již není dostupný. Nahraj soubor znovu.');
        $state = json_decode((string) file_get_contents($this->directory . '/state.json'), true, 32, JSON_THROW_ON_ERROR);
        return $state;
    }

    public function step(ProductPackageImporter $importer): array
    {
        if (($this->state()['kind'] ?? 'zip') !== 'zip') throw new InvalidArgumentException('Neplatný typ importu.');
        $mode = $this->state()['duplicate_mode'] ?? 'update';
        return $this->process(fn (array $item): array => $importer->import(new ProductZipArchive($this->directory . '/archive.zip'), $item['folder'], $item['new_key'], $mode));
    }

    private function process(callable $import): array
    {
        $this->state();
        $lock = fopen($this->directory . '/lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Import právě zpracovává jiný požadavek. Počkej a obnov stránku.');
        }
        try {
            $state = $this->state();
            foreach ($state['items'] as &$item) {
                if ($item['status'] === 'processing') {
                    $item['status'] = 'interrupted';
                    $item['message'] = 'Předchozí požadavek se přerušil. Před opakováním zkontroluj produkt v katalogu; tento krok neopakujeme automaticky.';
                }
                if ($item['status'] !== 'pending') continue;
                $item['status'] = 'processing';
                $this->write($state);
                try {
                    $saved = $import($item);
                    if (isset($saved['skipped'])) {
                        $item['status'] = 'skipped';
                        $item['message'] = $saved['message'];
                    } else {
                        $item['status'] = 'success';
                        $item['saved'] = $saved;
                        $item['message'] = $saved['message'] ?? ($saved['name'] . ' — uložena revize ' . $saved['revision_number']);
                    }
                } catch (Throwable $error) {
                    error_log('Product import: ' . $error->getMessage());
                    $item['status'] = 'error';
                    $item['message'] = $error instanceof InvalidArgumentException ? $error->getMessage() : 'Produkt se nepodařilo uložit. Zkontroluj práva images/, PHP GD a databázi; podrobnosti jsou v serverovém logu.';
                }
                break;
            }
            unset($item);
            $this->write($state);
            if (!in_array('pending', array_column($state['items'], 'status'), true)) @unlink($this->directory . '/archive.zip');
            return $state;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function remove(): void
    {
        foreach (['archive.zip', 'state.json', 'state.tmp', 'lock'] as $file) if (is_file($this->directory . '/' . $file)) @unlink($this->directory . '/' . $file);
        foreach (glob($this->directory . '/item-*.json') ?: [] as $file) @unlink($file);
        if (is_dir($this->directory)) @rmdir($this->directory);
    }

    /** Remove only expired job directories created by this class, never uploaded media. */
    public static function expire(): void
    {
        foreach (glob(sys_get_temp_dir() . '/simple-store-import-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_link($dir) || !preg_match('/simple-store-import-([a-f0-9]{48})$/D', $dir, $match)) continue;
            if (filemtime($dir) >= time() - 86400) continue;
            $lock = @fopen($dir . '/lock', 'c');
            if ($lock === false) continue;
            try {
                if (flock($lock, LOCK_EX | LOCK_NB)) (new self($match[1]))->remove();
            } finally { fclose($lock); }
        }
    }

    private function write(array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->directory . '/state.tmp', $json, LOCK_EX) !== strlen($json) ||
            !rename($this->directory . '/state.tmp', $this->directory . '/state.json')) throw new RuntimeException('Nelze uložit průběh importu.');
        chmod($this->directory . '/state.json', 0600);
    }
}
