<?php
declare(strict_types=1);

class MeekroDB
{
    public bool $tracking = false;
    public ?array $row = null;
    public array $executed = [];
    public int $failAt = 0;
    public bool $locked = false;

    public function queryFirstField(string $sql, mixed ...$args): mixed
    {
        if (str_contains($sql, 'SELECT DATABASE()')) return 'hosting_ag_shop';
        if (str_contains($sql, 'information_schema.TABLES')) return (int) $this->tracking;
        if (str_contains($sql, 'GET_LOCK')) {
            if ($this->locked) return 0;
            $this->locked = true;
            return 1;
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            $this->locked = false;
            return 1;
        }
        throw new RuntimeException('Unexpected scalar query.');
    }

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        return $this->row;
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_starts_with($sql, 'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES')) {
            return [];
        }
        if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS shop_schema_updates')) {
            $this->tracking = true;
        } elseif (str_starts_with($sql, 'INSERT INTO shop_schema_updates')) {
            $this->row = ['schema_hash' => $args[1], 'state' => 'running',
                'completed_statements' => 0, 'last_error' => null,
                'started_at' => '2026-09-29 12:00:00', 'finished_at' => null];
        } elseif (str_starts_with($sql, 'UPDATE shop_schema_updates SET completed_statements')) {
            $this->row['completed_statements'] = $args[0];
        } elseif (str_starts_with($sql, 'UPDATE shop_schema_updates SET state=')) {
            $this->row['state'] = $args[0];
            $this->row['last_error'] = $args[0] === 'failed' ? $args[1] : null;
        } else {
            $this->executed[] = $sql;
            if ($this->failAt > 0 && count($this->executed) === $this->failAt) {
                $this->failAt = 0;
                throw new RuntimeException('Simulated interrupted ALTER TABLE.');
            }
        }
        return [];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Database\SqlStatementParser;

$source = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
if (!is_string($source)) throw new RuntimeException('Schema file is missing.');
$parsed = SqlStatementParser::split($source);
$unprefixed = [];
foreach ($parsed as $statement) {
    if (preg_match('/^CREATE TABLE IF NOT EXISTS ([a-z0-9_]+)/', $statement, $matches) === 1 &&
        !str_starts_with($matches[1], 'shop_')) $unprefixed[] = $matches[1];
}
if ($unprefixed !== [] || count($parsed) < 100 ||
    !str_starts_with($parsed[2], 'CREATE TABLE IF NOT EXISTS shop_users') ||
    !str_contains(implode("\n", $parsed), "'ALTER TABLE shop_users") ||
    !str_contains(implode("\n", $parsed), 'CREATE TABLE IF NOT EXISTS shop_password_resets')) {
    throw new RuntimeException('The current schema did not parse into statements.');
}
$quoted = SqlStatementParser::split("-- ignored;\nSET @a='hello;''world'; /* ignored; */ SELECT `a;b` FROM t;\n");
if (count($quoted) !== 2 || !str_contains($quoted[0], "hello;''world") ||
    !str_contains($quoted[1], '`a;b`')) {
    throw new RuntimeException('Semicolons inside SQL strings or comments split statements.');
}
try {
    SqlStatementParser::split("CREATE TABLE t (name VARCHAR(10))");
    throw new RuntimeException('Incomplete SQL was accepted.');
} catch (RuntimeException $expected) {
    if ($expected->getMessage() === 'Incomplete SQL was accepted.') throw $expected;
}

$path = tempnam(sys_get_temp_dir(), 'schema-');
if ($path === false) throw new RuntimeException('Cannot create schema fixture.');
try {
    file_put_contents($path, $source);
    $db = new MeekroDB();
    $updater = new SchemaUpdater($db, $path);
    $status = $updater->status();
    if ($status['database'] !== 'hosting_ag_shop' || $status['current'] || $status['record'] !== null ||
        $status['count'] !== count($parsed) - 2) {
        throw new RuntimeException('The updater selected an incorrect target database or status.');
    }
    $db->failAt = 4;
    try {
        $updater->apply();
        throw new RuntimeException('Interrupted schema was marked successful.');
    } catch (RuntimeException $expected) {
        if (!str_contains($expected->getMessage(), 'Příkaz 4')) throw $expected;
    }
    if ($db->locked || $db->row['state'] !== 'failed' || $db->row['completed_statements'] !== 3) {
        throw new RuntimeException('Interrupted run did not release lock or save progress.');
    }
    if (!$updater->apply() || !$updater->status()['current'] ||
        count($db->executed) !== 4 + count($parsed) - 3) {
        throw new RuntimeException('Retry did not replay the schema or a completed schema was re-applied.');
    }
    $executed = count($db->executed);
    if ($updater->apply() || count($db->executed) !== $executed) {
        throw new RuntimeException('A completed schema was re-applied.');
    }
    foreach ($db->executed as $statement) {
        if (preg_match('/^(?:CREATE\s+DATABASE|USE\s+)/i', $statement)) {
            throw new RuntimeException('The admin switched away from the configured database.');
        }
    }
    file_put_contents($path, $source . "\n-- later revision\n");
    if ($updater->status()['current']) {
        throw new RuntimeException('A changed schema was not detected.');
    }
} finally {
    unlink($path);
}
echo "Schema updater tests passed.\n";
