<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use MeekroDB;

/** Shared by price maintenance and destructive catalog operations; never used by checkout. */
final class WorkerLock
{
    public function __construct(private MeekroDB $db) {}

    public function run(callable $action): mixed
    {
        $name = 'supplier-prices:' . sha1((string)$this->db->queryFirstField('SELECT DATABASE()'));
        if ((int)$this->db->queryFirstField('SELECT GET_LOCK(%s,0)', $name) !== 1) {
            throw new WorkerBusy('Právě probíhá kontrola partnerských nabídek. Počkej chvíli a akci zopakuj.');
        }
        try { return $action(); }
        finally { $this->db->query('SELECT RELEASE_LOCK(%s)', $name); }
    }
}
