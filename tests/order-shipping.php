<?php
declare(strict_types=1);

class MeekroDB
{
    public array $order;
    public ?array $packeta = null;
    public ?array $carrier = null;
    public array $events = [];
    public bool $failEvent = false;
    private ?array $snapshot = null;

    public function queryFirstField(string $sql, mixed ...$arguments): int { return 1; }
    public function startTransaction(): void
    {
        $this->snapshot = [$this->order, $this->packeta, $this->carrier, $this->events];
    }
    public function commit(): void { $this->snapshot = null; }
    public function rollback(): void
    {
        if ($this->snapshot !== null) {
            [$this->order, $this->packeta, $this->carrier, $this->events] = $this->snapshot;
            $this->snapshot = null;
        }
    }
    public function queryFirstRow(string $sql, mixed ...$arguments): ?array
    {
        if (str_contains($sql, 'FROM shop_orders')) return $this->order;
        if (str_contains($sql, 'FROM shop_packeta_shipments')) return $this->packeta;
        if (str_contains($sql, 'FROM shop_carrier_shipments')) return $this->carrier;
        throw new RuntimeException('Unexpected row lookup.');
    }
    public function query(string $sql, mixed ...$arguments): array
    {
        if (str_contains($sql, 'UPDATE shop_orders SET dispatch_shipping_json=NULL')) {
            $this->order['dispatch_shipping_json'] = null;
        } elseif (str_contains($sql, 'UPDATE shop_orders SET dispatch_shipping_json=')) {
            $this->order['dispatch_shipping_json'] = $arguments[0];
        } elseif (str_contains($sql, 'DELETE FROM shop_carrier_shipments')) {
            $this->carrier = null;
        } else {
            throw new RuntimeException('Unexpected write query.');
        }
        return [];
    }
    public function insert(string $table, array $row): void
    {
        if ($table !== 'shop_order_admin_events' || $this->failEvent) {
            throw new RuntimeException('Unable to record dispatch change.');
        }
        $this->events[] = $row;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Admin\OrderShippingRepository;
use SimpleStore\Checkout\ShippingPolicy;

function expectOrderShipping(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function rejectsShipping(callable $change, string $message): void
{
    try {
        $change();
        throw new RuntimeException($message);
    } catch (InvalidArgumentException $expected) {}
}

$original = ['method' => 'gls_home', 'label' => 'GLS – na adresu',
    'recipient' => 'Eva Nová', 'name' => 'Eva Nová', 'street' => 'Nádražní 1',
    'city' => 'Praha', 'postal_code' => '110 00', 'country' => 'CZ'];
$db = new MeekroDB();
$db->order = ['id' => 14, 'order_number' => 'DB-26-1234567890', 'status' => 'new',
    'payment_method' => 'bank_transfer', 'payment_status' => 'paid',
    'shipping_json' => json_encode($original, JSON_UNESCAPED_UNICODE),
    'dispatch_shipping_json' => null, 'shipping_czk' => 79, 'total_czk' => 202];
$shipping = new OrderShippingRepository($db, new ShippingPolicy(ShippingPolicy::defaults()));
expectOrderShipping($shipping->installed(), 'The migration was not recognized.');
$options = $shipping->optionsFor($db->order + ['shipping_ordered' => $original, 'shipping' => $original]);
expectOrderShipping(isset($options['ppl_home']) && !isset($options['gls_home']) &&
    !isset($options['ppl_pickup']), 'Unsafe shipping method was offered.');
$shipping->change(14, 1, 'ppl_home', 'gls_home', 'Doručí jiný dopravce');
$override = json_decode((string) $db->order['dispatch_shipping_json'], true);
expectOrderShipping($override['method'] === 'ppl_home' && $override['street'] === 'Nádražní 1' &&
    $db->order['shipping_json'] === json_encode($original, JSON_UNESCAPED_UNICODE) &&
    $db->order['shipping_czk'] === 79 && $db->order['total_czk'] === 202 &&
    $db->events[0]['old_status'] === 'gls_home' && $db->events[0]['new_status'] === 'ppl_home',
    'Changing courier altered the agreed price, address or audit history.');
rejectsShipping(static fn () => $shipping->change(14, 1, 'dpd_home', 'gls_home', 'Neaktuální formulář'),
    'Stale form succeeded.');
rejectsShipping(static fn () => $shipping->change(14, 1, 'gls_pickup', 'ppl_home', 'Nedoložená adresa boxu'),
    'Pickup address was invented.');

$db->packeta = ['status' => 'created'];
rejectsShipping(static fn () => $shipping->change(14, 1, 'dpd_home', 'ppl_home', 'Aktivní zásilka'),
    'Registered Packeta parcel was overwritten.');
$db->packeta = ['status' => 'cancelled'];
$db->carrier = ['status' => 'draft'];
rejectsShipping(static fn () => $shipping->change(14, 1, 'dpd_home', 'ppl_home', 'Připravené podklady'),
    'Carrier draft was discarded without confirmation.');
$db->failEvent = true;
try {
    $shipping->change(14, 1, 'dpd_home', 'ppl_home', 'Nedoložené podání', true);
    throw new RuntimeException('Audit failure did not roll back shipping change.');
} catch (RuntimeException $expected) {
    expectOrderShipping($db->carrier !== null && $db->order['dispatch_shipping_json'] ===
        json_encode($override, JSON_UNESCAPED_UNICODE), 'Audit failure left partial changes.');
}
$db->failEvent = false;
$shipping->change(14, 1, 'dpd_home', 'ppl_home', 'Změna před podáním', true);
expectOrderShipping($db->carrier === null &&
    json_decode((string) $db->order['dispatch_shipping_json'], true)['method'] === 'dpd_home',
    'Confirmed obsolete draft was not removed.');
$shipping->change(14, 1, 'gls_home', 'dpd_home', 'Vrácení původní dopravy');
expectOrderShipping($db->order['dispatch_shipping_json'] === null,
    'Restoring the originally agreed method should remove the dispatch override.');

$db->order['status'] = 'shipped';
rejectsShipping(static fn () => $shipping->change(14, 1, 'ppl_home', 'gls_home', 'Balík již odeslán'),
    'Already shipped parcel was changed.');
$db->order['status'] = 'new';
$db->order['payment_method'] = 'gopay';
$db->order['payment_status'] = 'pending';
rejectsShipping(static fn () => $shipping->change(14, 1, 'ppl_home', 'gls_home', 'Neuhrazená brána'),
    'Unpaid gateway order was changed.');
$db->order['payment_method'] = 'btcpay';
rejectsShipping(static fn () => $shipping->change(14, 1, 'ppl_home', 'gls_home', 'Neuhrazený bitcoin'),
    'Unpaid BTCPay order was changed.');
$db->order['payment_status'] = 'paid';
expectOrderShipping(isset($shipping->optionsFor($db->order + [
    'shipping_ordered' => $original, 'shipping' => $original])['ppl_home']),
    'Paid BTCPay order must allow an authorized change of delivery method.');

$pickupDb = new MeekroDB();
$pickup = ['method' => 'gls_pickup', 'label' => 'GLS – ParcelShop',
    'recipient' => 'Eva Nová', 'name' => 'Eva Nová', 'country' => 'CZ',
    'pickup_code' => 'P123', 'pickup_point' => 'Praha box', 'pickup_address' => 'Praha 1'];
$pickupDb->order = ['id' => 19, 'order_number' => 'DB-26-1234567891', 'status' => 'processing',
    'payment_method' => 'bank_transfer', 'payment_status' => 'paid',
    'shipping_json' => json_encode($pickup, JSON_UNESCAPED_UNICODE),
    'dispatch_shipping_json' => null, 'shipping_czk' => 59, 'total_czk' => 182];
$pickupShipping = new OrderShippingRepository($pickupDb, new ShippingPolicy(ShippingPolicy::defaults()));
expectOrderShipping($pickupShipping->needsAddress(['shipping' => $pickup]) &&
    isset($pickupShipping->optionsFor($pickupDb->order + [
        'shipping_ordered' => $pickup, 'shipping' => $pickup])['ppl_home']),
    'Pickup orders must offer home carriers with a required home address.');
rejectsShipping(static fn () => $pickupShipping->change(19, 1, 'ppl_home', 'gls_pickup',
    'Zákazník potřebuje doručení domů'), 'Missing verified address was accepted.');
rejectsShipping(static fn () => $pickupShipping->change(19, 1, 'ppl_home', 'gls_pickup',
    'Zákazník potřebuje doručení domů', false,
    ['street' => 'Nádražní 1', 'city' => 'Praha', 'postal_code' => '110 0'], true),
    'Incomplete post code was accepted.');
rejectsShipping(static fn () => $pickupShipping->change(19, 1, 'ppl_home', 'gls_pickup',
    'Zákazník potřebuje doručení domů', false,
    ['street' => 'Nádražní', 'city' => 'Praha', 'postal_code' => '110 00'], true),
    'Street without a house number was accepted.');
$pickupShipping->change(19, 1, 'ppl_home', 'gls_pickup',
    'Adresa ověřena se zákazníkem', false,
    ['street' => 'Nádražní 1', 'city' => 'Praha', 'postal_code' => '110 00'], true);
$redirected = json_decode((string) $pickupDb->order['dispatch_shipping_json'], true);
expectOrderShipping($redirected['method'] === 'ppl_home' &&
    $redirected['street'] === 'Nádražní 1' && !isset($redirected['pickup_code']) &&
    !isset($redirected['pickup_point']) && $pickupDb->order['shipping_czk'] === 59 &&
    $pickupDb->order['shipping_json'] === json_encode($pickup, JSON_UNESCAPED_UNICODE),
    'Pickup override lost its original choice, invented a pickup location or changed price.');
$pickupShipping->change(19, 1, 'dpd_home', 'ppl_home', 'Domluven jiný kurýr');
expectOrderShipping(json_decode((string) $pickupDb->order['dispatch_shipping_json'], true)['street'] ===
    'Nádražní 1', 'Second home carrier change dropped the newly entered address.');
$pickupShipping->change(19, 1, 'gls_pickup', 'dpd_home', 'Vrácení výdejního místa');
expectOrderShipping($pickupDb->order['dispatch_shipping_json'] === null,
    'Restoring the original pickup point did not remove the dispatch override.');

echo "Order shipping tests passed.\n";
