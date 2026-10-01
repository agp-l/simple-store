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
$comgateOrder = array_replace($order, ['payment_method' => 'comgate',
    'provider_reference' => 'CG123456']);
if (CarrierShipmentDraft::fromOrder($comgateOrder, $input)['pickup_code'] !== 'B10000') {
    throw new RuntimeException('A paid Comgate order cannot prepare its carrier shipment.');
}
$btcpayOrder = array_replace($order, ['payment_method' => 'btcpay',
    'provider_reference' => 'bitcoin-invoice-123']);
if (CarrierShipmentDraft::fromOrder($btcpayOrder, $input)['pickup_code'] !== 'B10000') {
    throw new RuntimeException('A paid BTCPay order cannot prepare its carrier shipment.');
}
try {
    CarrierShipmentCsv::export($draft);
    throw new RuntimeException('Pickup widget data must not create a Balíkovna import.');
} catch (InvalidArgumentException $expected) {}
$parse = static function (string $data): array {
    if (str_starts_with($data, "\xEF\xBB\xBF") || substr_count(trim($data), "\n") !== 0) {
        throw new RuntimeException('Default e-Balík file must have one data row and no BOM.');
    }
    return str_getcsv(trim($data), ';', '"', '');
};
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
$db->shipment = null;
$db->order['payment_method'] = 'comgate';
$repository->save(8, 2, CarrierShipmentDraft::fromOrder($comgateOrder, $input));
if ($repository->find(8)['status'] !== 'draft') {
    throw new RuntimeException('A paid Comgate shipment draft was rejected by storage.');
}

$gls = array_replace($order, ['shipping' => [
    'method' => 'gls_pickup', 'pickup_code' => '26711-GLSCZ_DEPO47',
    'pickup_point' => 'GLS ParcelShop Brno', 'pickup_address' => 'Nádražní 1, Brno, 602 00']]);
$gls['variable_symbol'] = '1234567890';
$glsDraft = CarrierShipmentDraft::fromOrder($gls, array_replace($input,
    CarrierShipmentDraft::nameDefaults($input['recipient']),
    CarrierShipmentDraft::addressDefaults($gls['shipping'])));
$glsCsv = CarrierShipmentCsv::export($glsDraft);
$glsRow = $parse($glsCsv);
if ($glsRow !== ['', '0.750', 'Eva', 'Nová', '', 'Nádražní', '1', 'Brno',
    '60200', 'CZ', '420777123456', 'eva@example.test', '', '', '1234567890', '',
    '26711-GLSCZ_DEPO47']) {
    throw new RuntimeException('GLS pickup import differs from the default 17-column portal layout.');
}
$home = array_replace($gls, ['shipping' => ['method' => 'gls_home', 'country' => 'CZ',
    'street' => 'Národní 1', 'city' => 'Praha', 'postal_code' => '11000']]);
$homeDraft = CarrierShipmentDraft::fromOrder($home,
    array_replace($input, CarrierShipmentDraft::nameDefaults($input['recipient']),
        CarrierShipmentDraft::addressDefaults($home['shipping'])));
$homeRow = $parse(CarrierShipmentCsv::export($homeDraft));
if (count($homeRow) !== 17 || $homeRow[5] !== 'Národní' || $homeRow[6] !== '1' ||
    $homeRow[8] !== '11000' || $homeRow[16] !== '') {
    throw new RuntimeException('GLS home import must contain the customer address without a ParcelShop ID.');
}
foreach ([
    array_replace($order, ['payment_status' => 'pending']),
    array_replace($comgateOrder, ['payment_status' => 'pending']),
    array_replace($btcpayOrder, ['payment_status' => 'pending']),
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
