<?php
declare(strict_types=1);
namespace SimpleStore\Product;
use MeekroDB;
use InvalidArgumentException;

/** Internal Czech delivery estimates, separate from the customer's shipping tariff. */
final class PartnerDeliveryCosts
{
    public const SCHEMA = "CREATE TABLE IF NOT EXISTS shop_partner_delivery_costs (
        partner_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        shipping_czk INT UNSIGNED NOT NULL, free_from_czk INT UNSIGNED DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    public function __construct(private MeekroDB $db) {}
    public function installed(): bool
    {
        return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','shop_partner_delivery_costs')===1;
    }
    public function all(): array
    {
        $result=[];
        if ($this->installed()) foreach ($this->db->query('SELECT * FROM shop_partner_delivery_costs') as $row) {
            $result[$row['partner_key']]=['shipping'=>(int)$row['shipping_czk'],'free_from'=>$row['free_from_czk']===null?null:(int)$row['free_from_czk']];
        }
        return $result;
    }
    public static function values(array $input): ?array
    {
        $values=[];
        foreach (['partner_shipping_czk','partner_free_from_czk'] as $field) {
            $value=$input[$field]??'';
            if (!is_string($value) || ($value!=='' && !preg_match('/^(0|[1-9][0-9]{0,6})$/D',$value))) throw new InvalidArgumentException('Poštovné a hranici dopravy zdarma zadej v celých Kč, nebo ponech prázdné.');
            $values[]=$value===''?null:(int)$value;
        }
        if ($values[0]===null && $values[1]!==null) throw new InvalidArgumentException('Nejprve vyplň běžné poštovné partnera.');
        return $values[0]===null?null:['shipping_czk'=>$values[0],'free_from_czk'=>$values[1]];
    }
    public function save(string $key, ?array $values): void
    {
        if (!$this->installed()) throw new InvalidArgumentException('Nejprve aktualizuj databázi v administraci.');
        if ($values===null) $this->db->query('DELETE FROM shop_partner_delivery_costs WHERE partner_key=%s',$key);
        else $this->db->replace('shop_partner_delivery_costs',['partner_key'=>$key]+$values);
    }
}
