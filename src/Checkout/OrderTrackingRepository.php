<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;

/** Manual tracking complements real identifiers saved by the carrier integrations. */
final class OrderTrackingRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_order_tracking') > 0;
    }

    public function manual(int $orderId): array
    {
        if (!$this->installed()) return ['number' => '', 'url' => ''];
        $row = $this->db->queryFirstRow('SELECT tracking_number, tracking_url FROM shop_order_tracking WHERE order_id=%i', $orderId);
        return ['number' => (string) ($row['tracking_number'] ?? ''), 'url' => (string) ($row['tracking_url'] ?? '')];
    }

    public function save(int $orderId, string $number, string $url): void
    {
        if ($orderId < 1 || !$this->installed()) throw new InvalidArgumentException('Aktualizuj SQL tabulky.');
        $number = trim($number);
        $url = trim($url);
        if (strlen($number) > 100 || preg_match('/[\x00-\x1f\x7f]/', $number) ||
            preg_match('//u', $number) !== 1 || strlen($url) > 1000 ||
            ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) ||
                parse_url($url, PHP_URL_SCHEME) !== 'https' ||
                parse_url($url, PHP_URL_USER) !== null ||
                parse_url($url, PHP_URL_FRAGMENT) !== null))) {
            throw new InvalidArgumentException('Vyplň platné číslo zásilky a veřejný HTTPS odkaz pro sledování.');
        }
        $this->db->query('INSERT INTO shop_order_tracking (order_id, tracking_number, tracking_url)
            VALUES (%i, %s, %s) ON DUPLICATE KEY UPDATE tracking_number=VALUES(tracking_number),
            tracking_url=VALUES(tracking_url), updated_at=UTC_TIMESTAMP()', $orderId, $number, $url);
    }

    public function forOrder(int $orderId): array
    {
        $manual = $this->manual($orderId);
        if ($manual['number'] !== '' || $manual['url'] !== '') return $manual;
        $packeta = new PacketaShipmentRepository($this->db);
        if ($packeta->installed()) {
            $shipment = $packeta->find($orderId);
            $url = PacketaShipmentRepository::trackingUrl($shipment);
            if ($url !== null) return ['number' => (string) ($shipment['barcode'] ?? $shipment['packet_id']), 'url' => $url];
        }
        $carrier = new CarrierShipmentRepository($this->db);
        if ($carrier->installed()) {
            $shipment = $carrier->find($orderId);
            if ($shipment !== null && $shipment['status'] === 'registered') {
                return ['number' => (string) ($shipment['tracking_number'] ?? ''), 'url' => ''];
            }
        }
        return ['number' => '', 'url' => ''];
    }
}
