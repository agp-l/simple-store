<?php
declare(strict_types=1);

namespace SimpleStore\Database;

use MeekroDB;
use RuntimeException;
use Throwable;

/** Applies the bundled repeatable schema to the configured connection only. */
final class SchemaUpdater
{
    public function __construct(private MeekroDB $db, private string $schemaPath)
    {
    }

    public function status(): array
    {
        $plan = $this->plan();
        $row = $this->trackingExists() ? $this->db->queryFirstRow(
            'SELECT schema_hash, state, completed_statements, last_error, started_at, finished_at
             FROM shop_schema_updates WHERE id=%i', 1) : null;
        return $plan + ['record' => $row, 'current' => $row !== null &&
            $row['state'] === 'complete' && hash_equals($plan['hash'], (string) $row['schema_hash'])];
    }

    /** @return array{database:string,hash:string,count:int,statements:list<string>} */
    private function plan(): array
    {
        $source = @file_get_contents($this->schemaPath);
        if (!is_string($source) || $source === '' || strlen($source) > 2097152) {
            throw new RuntimeException('Soubor database/schema.sql nelze načíst.');
        }
        $all = SqlStatementParser::split($source);
        if (count($all) < 3 ||
            preg_match('/^CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\b/i', $all[0]) !== 1 ||
            preg_match('/^USE\s+[`a-zA-Z0-9_]+$/iD', $all[1]) !== 1) {
            throw new RuntimeException('Začátek database/schema.sql neodpovídá očekávané podobě.');
        }
        // These two commands are for the CLI installer. The admin never changes databases.
        $statements = array_slice($all, 2);
        foreach ($statements as $statement) {
            if (preg_match('/^(?:CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS|SET\s+@|PREPARE\s+\w+\s+FROM\s+@|EXECUTE\s+\w+|DEALLOCATE\s+PREPARE\s+\w+|UPDATE\s+shop_orders\s+SET|INSERT\s+IGNORE\s+INTO\s+(?:catalog_categories|shop_product_inventory))\b/i', $statement) !== 1) {
                throw new RuntimeException('Soubor schématu obsahuje nepodporovaný příkaz. Aktualizaci nelze spustit.');
            }
        }
        $database = $this->db->queryFirstField('SELECT DATABASE()');
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('Web není připojený k vybrané databázi.');
        }
        return ['database' => $database, 'hash' => hash('sha256', $source),
            'count' => count($statements), 'statements' => $statements];
    }

    /** @return bool true if a new version was applied */
    public function apply(): bool
    {
        $plan = $this->plan();
        $lock = 'simple-store-schema:' . sha1($plan['database']);
        if ((int) $this->db->queryFirstField('SELECT GET_LOCK(%s, %i)', $lock, 0) !== 1) {
            throw new RuntimeException('Aktualizace databáze už probíhá. Zkus stránku za chvíli obnovit.');
        }
        try {
            $this->ensureTrackingTable();
            $row = $this->db->queryFirstRow(
                'SELECT schema_hash, state FROM shop_schema_updates WHERE id=%i', 1);
            if ($row !== null && $row['state'] === 'complete' &&
                hash_equals($plan['hash'], (string) $row['schema_hash'])) {
                return false;
            }
            $this->db->query('INSERT INTO shop_schema_updates
                (id, schema_hash, state, completed_statements, started_at, finished_at, last_error)
                VALUES (%i, %s, %s, %i, UTC_TIMESTAMP(), NULL, NULL)
                ON DUPLICATE KEY UPDATE schema_hash=VALUES(schema_hash), state=VALUES(state),
                    completed_statements=0, started_at=UTC_TIMESTAMP(),
                    finished_at=NULL, last_error=NULL', 1, $plan['hash'], 'running', 0);
            $completed = 0;
            try {
                foreach ($plan['statements'] as $statement) {
                    $this->db->query($statement);
                    $completed++;
                    $this->db->query('UPDATE shop_schema_updates SET completed_statements=%i WHERE id=%i',
                        $completed, 1);
                }
                $this->db->query('UPDATE shop_schema_updates SET state=%s, finished_at=UTC_TIMESTAMP(),
                    last_error=NULL WHERE id=%i', 'complete', 1);
            } catch (Throwable $error) {
                $message = 'Příkaz ' . ($completed + 1) . ' z ' . $plan['count'] . ': ' . $error->getMessage();
                $this->db->query('UPDATE shop_schema_updates SET state=%s, last_error=%s WHERE id=%i',
                    'failed', substr($message, 0, 500), 1);
                throw new RuntimeException($message, 0, $error);
            }
            return true;
        } finally {
            $this->db->queryFirstField('SELECT RELEASE_LOCK(%s)', $lock);
        }
    }

    private function trackingExists(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_schema_updates') > 0;
    }

    private function ensureTrackingTable(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS shop_schema_updates (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            schema_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            completed_statements SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(500) DEFAULT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
