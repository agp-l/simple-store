<?php
declare(strict_types=1);

namespace SimpleStore\Database;

use MeekroDB;
use RuntimeException;
use Throwable;

/** Rename the six legacy tables without copying rows or breaking the current application. */
final class LegacyTablePrefixMigration
{
    public const TABLES = [
        'users' => 'shop_users',
        'customer_addresses' => 'shop_customer_addresses',
        'content_revisions' => 'shop_content_revisions',
        'product_revisions' => 'shop_product_revisions',
        'catalog_categories' => 'shop_catalog_categories',
        'navigation_menus' => 'shop_navigation_menus',
    ];

    public function __construct(private MeekroDB $db)
    {
    }

    /** @return array{state:string,tables:array<string, array{old:?string,new:?string}>} */
    public function status(): array
    {
        $types = [];
        foreach ($this->db->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE()') as $row) {
            $types[(string) $row['TABLE_NAME']] = (string) $row['TABLE_TYPE'];
        }

        $tables = [];
        $legacy = $renamed = $empty = true;
        foreach (self::TABLES as $old => $new) {
            $source = $types[$old] ?? null;
            $target = $types[$new] ?? null;
            $tables[$old] = ['old' => $source, 'new' => $target];
            $legacy = $legacy && $source === 'BASE TABLE' && $target === null;
            $renamed = $renamed && $source === 'VIEW' && $target === 'BASE TABLE';
            $empty = $empty && $source === null && $target === null;
        }

        return ['state' => $legacy ? 'ready' : ($renamed ? 'migrated' : ($empty ? 'empty' : 'conflict')),
            'tables' => $tables];
    }

    /** @return bool true only if existing tables were renamed in this call */
    public function apply(): bool
    {
        $database = $this->db->queryFirstField('SELECT DATABASE()');
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('Není vybraná databáze obchodu.');
        }
        $lock = 'simple-store-schema:' . sha1($database);
        if ((int) $this->db->queryFirstField('SELECT GET_LOCK(%s, %i)', $lock, 0) !== 1) {
            throw new RuntimeException('Právě probíhá jiná úprava databáze.');
        }
        try {
            $state = $this->status()['state'];
            if ($state === 'migrated' || $state === 'empty') return false;
            if ($state !== 'ready') {
                throw new RuntimeException('Názvy tabulek jsou neúplné nebo kolidují s jiným projektem. Nic nebylo změněno.');
            }

            // Check CREATE VIEW and DROP VIEW privileges before moving any data.
            $probe = 'shop_prefix_probe_' . bin2hex(random_bytes(6));
            try {
                $this->db->query('CREATE ALGORITHM=MERGE VIEW `' . $probe . '` AS SELECT * FROM `users`');
                $this->db->query('DROP VIEW `' . $probe . '`');
            } catch (Throwable $error) {
                throw new RuntimeException('Databázový účet musí umět vytvářet a mazat pohledy. Tabulky nebyly přejmenovány.', 0, $error);
            }

            $pairs = [];
            foreach (self::TABLES as $old => $new) $pairs[] = '`' . $old . '` TO `' . $new . '`';
            $this->db->query('RENAME TABLE ' . implode(', ', $pairs));

            $created = [];
            try {
                foreach (self::TABLES as $old => $new) {
                    $this->db->query('CREATE ALGORITHM=MERGE VIEW `' . $old . '` AS SELECT * FROM `' . $new . '`');
                    $created[] = $old;
                }
                $views = $this->db->query('SELECT TABLE_NAME, IS_UPDATABLE FROM information_schema.VIEWS
                    WHERE TABLE_SCHEMA=DATABASE()');
                $updatable = [];
                foreach ($views as $row) $updatable[(string) $row['TABLE_NAME']] = (string) $row['IS_UPDATABLE'];
                foreach (self::TABLES as $old => $new) {
                    if (($updatable[$old] ?? '') !== 'YES') {
                        throw new RuntimeException('Pohled ' . $old . ' nepovoluje zápis.');
                    }
                }
            } catch (Throwable $error) {
                try {
                    foreach (array_reverse($created) as $old) $this->db->query('DROP VIEW `' . $old . '`');
                    $undo = [];
                    foreach (self::TABLES as $old => $new) $undo[] = '`' . $new . '` TO `' . $old . '`';
                    $this->db->query('RENAME TABLE ' . implode(', ', $undo));
                } catch (Throwable $rollback) {
                    throw new RuntimeException('Převod se nepodařil a nešlo vrátit názvy tabulek. Zkontroluj databázi: '
                        . $rollback->getMessage(), 0, $error);
                }
                throw new RuntimeException('Převod se nepodařil; původní názvy tabulek byly obnoveny: '
                    . $error->getMessage(), 0, $error);
            }
            return true;
        } finally {
            $this->db->queryFirstField('SELECT RELEASE_LOCK(%s)', $lock);
        }
    }
}
