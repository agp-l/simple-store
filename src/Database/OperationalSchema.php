<?php
declare(strict_types=1);
namespace SimpleStore\Database;

use MeekroDB;

/** Additive worker state only. Called after worker authentication, never on storefront requests. */
final class OperationalSchema
{
    public static function install(MeekroDB $db): void
    {
        foreach (self::statements() as $sql) $db->query($sql);
    }
    public static function statements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS shop_partner_stock_state (product_key CHAR(32) NOT NULL PRIMARY KEY, partner_id INT UNSIGNED NOT NULL, observed_at BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS shop_btc_rate_refresh (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, next_attempt_at BIGINT UNSIGNED NOT NULL DEFAULT 0, failures INT UNSIGNED NOT NULL DEFAULT 0, last_http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS shop_partner_refresh_requests (product_key CHAR(32) NOT NULL PRIMARY KEY, requested_after BIGINT UNSIGNED NOT NULL DEFAULT 0, generation BIGINT UNSIGNED NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        \SimpleStore\Product\PartnerDeliveryCosts::SCHEMA,
        ...\SimpleStore\Supplier\PartnerOfferCatalog::SCHEMA,
        ];
    }
}
