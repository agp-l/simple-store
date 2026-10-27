<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use MeekroDB;

/** Transactional outbox: no HTTP or worker lock in a checkout transaction. */
final class PartnerRefreshQueue
{
    public function __construct(private MeekroDB $db) {}
    public function installed(): bool
    {
        return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','shop_partner_refresh_requests')===1;
    }
    public function request(string $key, int $after): void
    {
        $this->db->query('INSERT INTO shop_partner_refresh_requests (product_key,requested_after) SELECT %s,%i FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL LIMIT 1 ON DUPLICATE KEY UPDATE requested_after=GREATEST(requested_after,VALUES(requested_after)),generation=generation+1',$key,$after,$key);
    }
    /** Historic ledgers predate per-order partner limits. Use the immutable order mode. */
    public function assistedKeys(int $orderId): array
    {
        $json=$this->db->queryFirstField('SELECT items_json FROM shop_orders WHERE id=%i',$orderId);
        $keys=[];
        foreach(json_decode($json??'[]',true)??[] as $item)if(($item['sale_mode']??'own')==='assisted')$keys[$item['product_key']]=true;
        return $keys;
    }
}
