<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\PacketaPickupPoint;

$requests = [];
$response = ['status' => 200, 'body' => json_encode(['isValid' => true, 'point' => [
    'name' => 'Praha, Českomoravská', 'address' => [
        'street' => 'Českomoravská 2408', 'city' => 'Praha', 'zip' => '190 00', 'country' => 'cz',
    ],
]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
$transport = static function (string $url, string $body) use (&$requests, &$response): array {
    $requests[] = [$url, json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
    return $response;
};
$packeta = new PacketaPickupPoint('ABCDEF1234567890', $transport);
$details = $packeta->verify('123456');
if ($details !== ['pickup_code' => '123456', 'pickup_point' => 'Praha, Českomoravská',
    'pickup_address' => 'Českomoravská 2408, Praha, 190 00'] ||
    $requests[0][0] !== 'https://widget.packeta.com/v6/pps/api/widget/v1/validate' ||
    $requests[0][1]['apiKey'] !== 'ABCDEF1234567890' ||
    $requests[0][1]['point'] !== ['id' => '123456'] ||
    $requests[0][1]['options']['country'] !== 'cz' ||
    $requests[0][1]['options']['vendors'] !== PacketaPickupPoint::options()['vendors']) {
    throw new RuntimeException('Packeta request or authoritative pickup point data are wrong.');
}

$invalid = static function (callable $operation): void {
    try {
        $operation();
        throw new RuntimeException('Unverified Packeta point was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
};
$invalid(static fn () => $packeta->verify('123;external'));
if (count($requests) !== 1) throw new RuntimeException('Invalid branch ID reached the external service.');
$response = ['status' => 200, 'body' => '{"isValid":false,"errors":[{"code":"PickupPointIsFull"}]}'];
$invalid(static fn () => $packeta->verify('123456'));
$response = ['status' => 200, 'body' => '{"isValid":true,"point":{"name":"Cizí bod","address":{"street":"A","city":"B","zip":"1","country":"sk"}}}'];
$invalid(static fn () => $packeta->verify('123456'));
$response = ['status' => 401, 'body' => '{}'];
$invalid(static fn () => $packeta->verify('123456'));
$response = ['status' => 0, 'body' => ''];
$invalid(static fn () => $packeta->verify('123456'));
$invalid(static fn () => (new PacketaPickupPoint())->verify('123456'));
$invalid(static fn () => new PacketaPickupPoint('not-a-widget-key'));

echo "Packeta pickup validation tests passed.\n";
