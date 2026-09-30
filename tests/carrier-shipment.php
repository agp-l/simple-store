<?php
declare(strict_types=1);

class MeekroDB
{
    public array $order = [];
    public ?array $shipment = null;
    private ?array $snapshot = null;

    public function queryFirstField(string $sql, mixed ...$args): int { return 1; }
    public function startTransaction(): void { $this->snapshot = [$this->order, $this->shipment]; }
    public function commit(): void { $this->snapshot = null; }
    public function rollback(): void
    {
        [$this->order, $this->shipment] = $this->snapshot;
        $this->snapshot = null;
    }
    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        return str_contains($sql, 'FROM shop_orders') ? $this->order : $this->shipment;
    }
    public function insert(string $table, array $values): void
    {
        if ($table !== 'shop_carrier_shipments' || $this->shipment !== null) {
            throw new RuntimeException('Duplicate shipment.');
        }
        $this->shipment = $values + ['tracking_number' => null];
    }
    public function query(string $sql, mixed ...$args): array
    {
        if ($this->shipment === null) throw new RuntimeException('No shipment.');
        if (str_contains($sql, 'draft_json=%s')) {
            $this->shipment['draft_json'] = $args[0];
        } elseif (str_contains($sql, 'tracking_number=%s')) {
            $this->shipment['status'] = $args[0];
            $this->shipment['tracking_number'] = $args[1];
        } else {
            throw new RuntimeException('Unexpected SQL.');
        }
        return [];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\CarrierShipmentCsv;
use SimpleStore\Checkout\CarrierShipmentDraft;
use SimpleStore\Checkout\CarrierShipmentRepository;

$db = new MeekroDB();
$repository = new CarrierShipmentRepository($db);
$order = ['order_number' => 'DB-20260930-123', 'payment_method' => 'bank_transfer',
    'payment_status' => 'paid', 'status' => 'processing', 'fulfillment_source' => 'own',
    'shipping' => ['method' => 'balikovna_pickup', 'pickup_code' => 'B10000',
        'pickup_postal_code' => '11000', 'pickup_point' => 'Balíkovna Praha',
        'pickup_address' => 'Národní 1, 110 00 Praha']];
$input = ['recipient' => 'Eva Nová', 'email' => 'eva@example.test',
    'phone' => '+420 777 123 456', 'weight_kg' => '0,750', 'city' => 'Praha'];
$draft = CarrierShipmentDraft::fromOrder($order, $input);
$csv = CarrierShipmentCsv::export($draft);
$parse = static function (string $data): array {
    $lines = explode("\n", substr($data, 3));
    return [str_getcsv($lines[0], ';', '"', ''), str_getcsv($lines[1], ';', '"', '')];
};
[$balHeaders, $balRow] = $parse($csv);
if (!str_starts_with($csv, "\xEF\xBB\xBF") ||
    $balRow !== ['DB-20260930-123', 'NB', 'Eva Nová', 'B10000', 'Praha',
        '420777123456', 'eva@example.test', '0.750'] ||
    str_contains($csv, '11000')) {
    throw new RuntimeException('Balíkovna import must contain the point ID, never its physical postcode.');
}
$db->order = ['shipping_json' => json_encode($order['shipping']), 'order_number' => $order['order_number'],
    'status' => 'processing', 'payment_status' => 'paid', 'payment_method' => 'bank_transfer',
    'fulfillment_source' => 'own'];
$repository->save(7, 2, $draft);
if ($repository->find(7)['draft']['pickup_code'] !== 'B10000') {
    throw new RuntimeException('Carrier draft did not survive storage.');
}
$repository->register(7, 2, 'DR123456789CZ');
if ($repository->find(7)['tracking_number'] !== 'DR123456789CZ' ||
    $db->order['status'] !== 'processing') {
    throw new RuntimeException('Carrier number must not mark the order as shipped.');
}
try {
    $repository->save(7, 2, $draft);
    throw new RuntimeException('Confirmed carrier shipment was overwritten.');
} catch (InvalidArgumentException $expected) {}

$gls = array_replace($order, ['shipping' => [
    'method' => 'gls_pickup', 'pickup_code' => '26711-GLSCZ_DEPO47',
    'pickup_point' => 'GLS ParcelShop Brno', 'pickup_address' => 'Nádražní 1, Brno, 602 00']]);
$glsDraft = CarrierShipmentDraft::fromOrder($gls, array_replace($input,
    CarrierShipmentDraft::addressDefaults($gls['shipping'])));
$glsCsv = CarrierShipmentCsv::export($glsDraft);
[$glsHeaders, $glsRow] = $parse($glsCsv);
if ($glsRow[1] !== 'GLS ParcelShop Brno' || $glsRow[2] !== 'Eva Nová' ||
    $glsRow[3] !== 'Nádražní 1' || $glsRow[4] !== 'Brno' ||
    $glsRow[5] !== '60200' || $glsRow[10] !== 'PSD(26711-GLSCZ_DEPO47)') {
    throw new RuntimeException('GLS pickup import omitted the PSD service or point destination.');
}
$home = array_replace($gls, ['shipping' => ['method' => 'gls_home', 'country' => 'CZ',
    'street' => 'Národní 1', 'city' => 'Praha', 'postal_code' => '11000']]);
$homeDraft = CarrierShipmentDraft::fromOrder($home,
    array_replace($input, CarrierShipmentDraft::addressDefaults($home['shipping'])));
if (str_contains(CarrierShipmentCsv::export($homeDraft), 'PSD(')) {
    throw new RuntimeException('GLS home must not use pickup service.');
}
foreach ([
    array_replace($order, ['payment_status' => 'pending']),
    array_replace($order, ['fulfillment_source' => 'external']),
    array_replace($order, ['status' => 'shipped']),
] as $invalidOrder) {
    try {
        CarrierShipmentDraft::fromOrder($invalidOrder, $input);
        throw new RuntimeException('Ineligible order was prepared.');
    } catch (InvalidArgumentException $expected) {}
}
try {
    CarrierShipmentDraft::fromOrder($order, array_replace($input, ['recipient' => '=HYPERLINK("x")']));
    throw new RuntimeException('CSV formula was accepted.');
} catch (InvalidArgumentException $expected) {}

echo "Carrier shipment preparation OK\n";
