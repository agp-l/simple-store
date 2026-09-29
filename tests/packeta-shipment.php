<?php
declare(strict_types=1);

class MeekroDB
{
    public ?array $shipment = null;
    public array $order = [];
    private ?array $snapshot = null;

    public function queryFirstField(string $sql, mixed ...$args): int { return 1; }
    public function startTransaction(): void { $this->snapshot = $this->shipment; }
    public function commit(): void { $this->snapshot = null; }
    public function rollback(): void { $this->shipment = $this->snapshot; }
    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        return str_contains($sql, 'FROM shop_orders') ? $this->order : $this->shipment;
    }
    public function insert(string $table, array $values): void
    {
        if ($this->shipment !== null) throw new RuntimeException('Duplicate shipment.');
        $this->shipment = ['packet_id' => null, 'barcode' => null,
            'barcode_text' => null, 'courier_number' => null, 'last_error' => null] + $values;
    }
    public function query(string $sql, mixed ...$values): array
    {
        if ($this->shipment === null) throw new RuntimeException('No shipment.');
        if (str_contains($sql, 'SET status=%s, packet_id=%s')) {
            if (in_array($this->shipment['status'], ['submitting', 'uncertain'], true)) {
                [$this->shipment['status'], $this->shipment['packet_id'],
                    $this->shipment['barcode'], $this->shipment['barcode_text']] = array_slice($values, 0, 4);
            }
        } elseif (str_contains($sql, 'SET status=%s, last_error=%s')) {
            $this->shipment['status'] = $values[0];
            $this->shipment['last_error'] = $values[1];
        } elseif (str_contains($sql, 'SET status=%s, method=%s')) {
            if ($this->shipment['status'] !== 'rejected') throw new RuntimeException('Unexpected retry.');
            $this->shipment['status'] = $values[0];
        } elseif (str_contains($sql, 'SET status=%s,')) {
            $this->shipment['status'] = $values[0];
        } elseif (str_contains($sql, 'SET courier_number=%s')) {
            $this->shipment['courier_number'] = $values[0];
        } else throw new RuntimeException('Unexpected query.');
        return [];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\PacketaApiClient;
use SimpleStore\Checkout\PacketaRejectedException;
use SimpleStore\Checkout\PacketaShipmentDraft;
use SimpleStore\Checkout\PacketaShipmentRepository;

$shipping = ['method' => 'zasilkovna_pickup', 'pickup_verified' => true,
    'pickup_code' => '12345', 'phone' => '+420777111222'];
$order = ['order_number' => 'DB-20260929-ABC', 'customer_email' => 'eva@example.test',
    'subtotal_czk' => 1200, 'shipping' => $shipping,
    'payment_method' => 'bank_transfer', 'payment_status' => 'paid', 'status' => 'processing'];
$form = ['first_name' => 'Eva', 'surname' => 'Nová', 'weight_kg' => '0,750',
    'email' => 'eva@example.test', 'phone' => '+420 777-111-222'];
$pickup = PacketaShipmentDraft::fromOrder($order, $form, 'Dobrodruzi');
if ($pickup['attributes']['addressId'] !== '12345' || $pickup['attributes']['weight'] !== '0.750' ||
    $pickup['attributes']['phone'] !== '+420777111222' ||
    $pickup['attributes']['cod'] !== '0' || $pickup['attributes']['value'] !== '1200') {
    throw new RuntimeException('Pickup packet has wrong destination or financial attributes.');
}
$home = PacketaShipmentDraft::fromOrder(array_replace($order, ['shipping' => [
    'method' => 'zasilkovna_home', 'phone' => '+420777111222', 'country' => 'CZ',
    'city' => 'Praha', 'postal_code' => '190 00']]), $form + [
    'street' => 'Českomoravská', 'house_number' => '2408/1a',
    'city' => 'Praha', 'postal_code' => '190 00'], 'Dobrodruzi');
if ($home['attributes']['addressId'] !== '106' || $home['attributes']['zip'] !== '19000' ||
    $home['attributes']['houseNumber'] !== '2408/1a') {
    throw new RuntimeException('HD destination was not normalized.');
}
$corrected = PacketaShipmentDraft::fromOrder($order, array_replace($form, [
    'email' => 'oprava@example.test', 'phone' => '+420 (777) 444-555']), 'Dobrodruzi');
if ($corrected['attributes']['email'] !== 'oprava@example.test' ||
    $corrected['attributes']['phone'] !== '+420777444555' ||
    $order['customer_email'] !== 'eva@example.test') {
    throw new RuntimeException('Contact correction changed the order or was not sent to Packeta.');
}
foreach ([array_replace($order, ['payment_status' => 'pending']),
    array_replace($order, ['shipping' => array_replace($shipping, ['pickup_verified' => false])])] as $invalid) {
    try {
        PacketaShipmentDraft::fromOrder($invalid, $form, 'Dobrodruzi');
        throw new RuntimeException('Invalid order would be dispatched.');
    } catch (InvalidArgumentException $expected) {}
}

$db = new MeekroDB();
$db->order = ['payment_method' => 'bank_transfer', 'payment_status' => 'paid',
    'status' => 'processing', 'shipping_json' => json_encode($shipping, JSON_THROW_ON_ERROR)];
$repo = new PacketaShipmentRepository($db);
$repo->reserve(9, 3, $pickup);
try {
    $repo->reserve(9, 3, $pickup);
    throw new RuntimeException('Duplicate API submission was not blocked.');
} catch (InvalidArgumentException $expected) {}
$repo->failed(9, 'Zásilkovna odmítla adresu.', true);
$repo->reserve(9, 3, $pickup);
$repo->failed(9, 'Nejistá odpověď', false);
try {
    $repo->reserve(9, 3, $pickup);
    throw new RuntimeException('Ambiguous API submission was retried.');
} catch (InvalidArgumentException $expected) {}
$repo->reconcile(9, 'Z1234567890');
if ($repo->find(9)['status'] !== 'created' || $repo->find(9)['packet_id'] !== '1234567890') {
    throw new RuntimeException('Manual recovery lost the found packet number.');
}

if (function_exists('simplexml_load_string')) {
    $calls = [];
    $transport = static function (string $url, string $xml) use (&$calls): array {
        $calls[] = [$url, $xml];
        if (str_contains($xml, '<createPacket>')) {
            return ['status' => 200, 'body' => '<response><status>ok</status><result><id>1234567890</id><barcode>Z1234567890</barcode><barcodeText>Z 123 4567 890</barcodeText></result></response>'];
        }
        if (str_contains($xml, '<packetCourierNumber>')) {
            return ['status' => 200, 'body' => '<response><status>ok</status><result>98765</result></response>'];
        }
        return ['status' => 200, 'body' => '<response><status>ok</status><result>' .
            base64_encode('%PDF-1.4 test') . '</result></response>'];
    };
    $api = new PacketaApiClient('secret-password', $transport);
    $packet = $api->createPacket(array_replace($home['attributes'], ['name' => 'Eva & děti']));
    if ($packet['barcode'] !== 'Z1234567890' ||
        !str_contains($calls[0][1], '<name>Eva &amp; děti</name>') ||
        !str_contains($calls[0][1], '<addressId>106</addressId>') ||
        $api->courierNumber($packet['barcode']) !== '98765' ||
        $api->labelPdf($packet['barcode']) !== '%PDF-1.4 test' ||
        $api->labelPdf($packet['barcode'], '98765') !== '%PDF-1.4 test' ||
        !str_contains(end($calls)[1], '<courierNumber>98765</courierNumber>')) {
        throw new RuntimeException('Packeta API XML, courier number or label handling failed.');
    }
    $fault = new PacketaApiClient('secret-password', static fn (): array => [
        'status' => 200, 'body' => '<response><status>fault</status><fault><string>Invalid address</string></fault></response>']);
    try {
        $fault->createPacket($pickup['attributes']);
        throw new RuntimeException('An explicit Packeta fault was accepted.');
    } catch (PacketaRejectedException $expected) {}
}
echo "Packeta shipment tests passed.\n";
