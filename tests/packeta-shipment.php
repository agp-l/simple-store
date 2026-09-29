<?php
declare(strict_types=1);

class MeekroDB
{
    public ?array $shipment = null;
    public array $order = [];
    public array $history = [];
    private ?array $snapshot = null;

    public function queryFirstField(string $sql, mixed ...$args): int { return 1; }
    public function startTransaction(): void { $this->snapshot = [$this->shipment, $this->history, $this->order]; }
    public function commit(): void { $this->snapshot = null; }
    public function rollback(): void { [$this->shipment, $this->history, $this->order] = $this->snapshot; }
    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        return str_contains($sql, 'FROM shop_orders') ? $this->order : $this->shipment;
    }
    public function insert(string $table, array $values): void
    {
        if ($table === 'shop_packeta_cancelled_shipments') {
            $this->history[] = $values + ['cancelled_at' => '2026-09-30 00:00:00'];
            return;
        }
        if ($this->shipment !== null) throw new RuntimeException('Duplicate shipment.');
        $this->shipment = ['packet_id' => null, 'barcode' => null,
            'barcode_text' => null, 'courier_number' => null, 'last_error' => null] + $values;
    }
    public function query(string $sql, mixed ...$values): array
    {
        if (str_contains($sql, 'FROM shop_packeta_cancelled_shipments')) return $this->history;
        if (str_contains($sql, 'UPDATE shop_orders SET status=%s')) {
            if ($this->order['status'] === $values[2]) $this->order['status'] = $values[0];
            return [];
        }
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
            if (!in_array($this->shipment['status'], ['rejected', 'cancelled'], true)) {
                throw new RuntimeException('Unexpected retry.');
            }
            $this->shipment['status'] = $values[0];
            $this->shipment['packet_id'] = $this->shipment['barcode'] = null;
            $this->shipment['barcode_text'] = $this->shipment['courier_number'] = null;
            $this->shipment['submitted_json'] = $values[3];
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
if ($repo->reserveCancellation(9, 3) !== '1234567890' ||
    $repo->find(9)['status'] !== 'cancelling') {
    throw new RuntimeException('Cancellation did not reserve the original packet ID.');
}
try {
    $repo->reserveCancellation(9, 3);
    throw new RuntimeException('A duplicate cancellation was accepted.');
} catch (InvalidArgumentException $expected) {}
$repo->cancellationFailed(9, 'Zásilkovna storno odmítla.', true);
if ($repo->find(9)['status'] !== 'created') throw new RuntimeException('Rejected cancellation lost the packet.');
$repo->reserveCancellation(9, 3);
$repo->cancellationFailed(9, 'Nejasná odpověď', false);
if ($repo->find(9)['status'] !== 'cancel_uncertain') {
    throw new RuntimeException('Uncertain cancellation became retryable.');
}
$repo->cancellationNotDone(9, 'cancel_uncertain');
if ($repo->find(9)['status'] !== 'created' ||
    $repo->find(9)['barcode'] !== 'Z1234567890') {
    throw new RuntimeException('Manual resolution erased an active parcel.');
}
$repo->reserveCancellation(9, 3);
$repo->cancellationFailed(9, 'Nejasná odpověď', false);
try {
    $repo->reserve(9, 3, $pickup);
    throw new RuntimeException('A new parcel was created before the cancellation was resolved.');
} catch (InvalidArgumentException $expected) {}
$db->order['status'] = 'ready_to_ship';
$repo->completeCancellation(9, 3, 'cancel_uncertain');
if ($repo->find(9)['status'] !== 'cancelled' ||
    $repo->cancelledForOrder(9)[0]['barcode'] !== 'Z1234567890' ||
    $db->order['status'] !== 'processing' || PacketaShipmentRepository::trackingUrl($repo->find(9)) !== null) {
    throw new RuntimeException('Cancellation did not preserve the old parcel in history.');
}
$repo->reserve(9, 3, $pickup);
if ($repo->find(9)['status'] !== 'submitting' || $repo->find(9)['packet_id'] !== null ||
    $repo->find(9)['barcode'] !== null) {
    throw new RuntimeException('Replacement parcel reused a cancelled barcode.');
}
$repo->complete(9, ['id' => '1234567891', 'barcode' => 'Z1234567891',
    'barcode_text' => 'Z 123 4567 891']);
if (PacketaShipmentRepository::trackingUrl($repo->find(9)) !==
    'https://tracking.packeta.com/cs/?id=1234567891') {
    throw new RuntimeException('The active parcel tracking URL is missing.');
}
$db->order['status'] = 'shipped';
try {
    $repo->reserveCancellation(9, 3);
    throw new RuntimeException('Cancellation was allowed after shipping.');
} catch (InvalidArgumentException $expected) {}

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
    $cancel = new PacketaApiClient('secret-password', static function (string $url, string $xml): array {
        if (!str_contains($xml, '<cancelPacket>') ||
            !str_contains($xml, '<packetId>1234567890</packetId>')) {
            throw new RuntimeException('Cancellation sent an invalid API request.');
        }
        return ['status' => 200, 'body' => '<response><status>ok</status></response>'];
    });
    $cancel->cancelPacket('1234567890');
    $fault = new PacketaApiClient('secret-password', static fn (): array => [
        'status' => 200, 'body' => '<response><status>fault</status><fault><string>Invalid address</string></fault></response>']);
    try {
        $fault->createPacket($pickup['attributes']);
        throw new RuntimeException('An explicit Packeta fault was accepted.');
    } catch (PacketaRejectedException $expected) {
        if (!str_contains($expected->getMessage(), 'Invalid address')) {
            throw new RuntimeException('The plain Packeta fault message was lost.');
        }
    }
    $fieldFault = new PacketaApiClient('secret-password', static fn (): array => [
        'status' => 400, 'body' => '<response><status>fault</status><fault>' .
            '<faultCode>PacketAttributesFault</faultCode><attributes>' .
            '<fault><name>eshop</name><fault>Unknown sender</fault></fault>' .
            '<fault><name>weight</name><fault>Too heavy</fault></fault>' .
            '</attributes></fault></response>']);
    try {
        $fieldFault->createPacket($pickup['attributes']);
        throw new RuntimeException('A field validation fault was accepted.');
    } catch (PacketaRejectedException $expected) {
        foreach (['PacketAttributesFault', 'eshop: Unknown sender', 'weight: Too heavy'] as $detail) {
            if (!str_contains($expected->getMessage(), $detail)) {
                throw new RuntimeException('The Packeta field fault detail was lost: ' . $detail);
            }
        }
    }
    $restFault = new PacketaApiClient('secret-password', static fn (): array => [
        'status' => 200, 'body' => '<response><status>fault</status>' .
            '<fault>PacketAttributesFault</fault><string>Invalid packet attributes</string>' .
            '<detail><attributes><fault><name>eshop</name><fault>Sender does not exist</fault></fault>' .
            '<fault><name>weight</name><fault>Weight is required</fault></fault></attributes></detail>' .
            '</response>']);
    try {
        $restFault->createPacket($pickup['attributes']);
        throw new RuntimeException('A REST validation fault was accepted.');
    } catch (PacketaRejectedException $expected) {
        foreach (['(PacketAttributesFault)', 'eshop: Sender does not exist',
            'weight: Weight is required', 'Invalid packet attributes'] as $detail) {
            if (!str_contains($expected->getMessage(), $detail)) {
                throw new RuntimeException('The REST fault detail was lost: ' . $detail);
            }
        }
        if (str_contains($expected->getMessage(), '(HTTP 200)')) {
            throw new RuntimeException('HTTP status was mistaken for the REST fault code.');
        }
    }
    $nestedFault = new PacketaApiClient('secret-password', static fn (): array => [
        'status' => 422, 'body' => '<response><status>fault</status><fault><detail>' .
            '<PacketAttributesFault><attributes><fault><name>phone</name>' .
            '<fault>Invalid number</fault></fault></attributes></PacketAttributesFault>' .
            '</detail></fault></response>']);
    try {
        $nestedFault->createPacket($pickup['attributes']);
        throw new RuntimeException('A nested validation fault was accepted.');
    } catch (PacketaRejectedException $expected) {
        if (!str_contains($expected->getMessage(), 'phone: Invalid number') ||
            !str_contains($expected->getMessage(), 'PacketAttributesFault')) {
            throw new RuntimeException('The nested Packeta fault detail was lost.');
        }
    }
    $passwordFault = new PacketaApiClient('secret-password', static fn (): array => [
        'status' => 401, 'body' => '<response><status>fault</status><fault>' .
            '<faultCode>IncorrectApiPasswordFault</faultCode>' .
            '<faultString>Invalid secret-password</faultString></fault></response>']);
    try {
        $passwordFault->createPacket($pickup['attributes']);
        throw new RuntimeException('An authentication fault was accepted.');
    } catch (PacketaRejectedException $expected) {
        if (!str_contains($expected->getMessage(), 'IncorrectApiPasswordFault') ||
            !str_contains($expected->getMessage(), '[skryto]') ||
            str_contains($expected->getMessage(), 'secret-password')) {
            throw new RuntimeException('The Packeta authentication fault exposed the password or lost its code.');
        }
    }
}
echo "Packeta shipment tests passed.\n";
