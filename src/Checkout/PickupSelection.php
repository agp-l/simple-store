<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

/** Validates carrier pickup choices before saving and again before placing an order. */
final class PickupSelection
{
    public function __construct(
        private PacketaPickupPoint $packeta,
        private PplPickupPoint $ppl,
        private GlsPickupPoint $gls,
        private BalikovnaPickupPoint $balikovna
    ) {
    }

    public function packeta(): PacketaPickupPoint
    {
        return $this->packeta;
    }

    public function ppl(): PplPickupPoint
    {
        return $this->ppl;
    }

    /** The field reader applies the checkout's length and type checks to carrier widget values. */
    public function fromPost(string $method, array $fields, callable $field): array
    {
        if ($method === 'zasilkovna_pickup' && $this->packeta->isConfigured()) {
            return array_replace($fields, $this->packeta->verify($field('packeta_point_id')));
        }
        if ($method === 'ppl_pickup' && $this->ppl->isConfigured()) {
            return array_replace($fields, $this->ppl->selection(
                $field('ppl_point_code'), $field('ppl_point_name'),
                $field('ppl_point_address'), $field('ppl_point_country')
            ));
        }
        if ($method === 'gls_pickup') {
            return array_replace($fields, $this->gls->selection(
                $field('gls_point_id'), $field('gls_point_name'),
                $field('gls_point_address'), $field('gls_point_country')
            ));
        }
        if ($method === 'balikovna_pickup') {
            return array_replace($fields, $this->balikovna->selection(
                $field('balikovna_point_id'), $field('balikovna_point_name'),
                $field('balikovna_point_address'), $field('balikovna_point_zip'),
                $field('balikovna_point_type')
            ));
        }
        return $fields;
    }

    public function revalidate(string $method, array $delivery): array
    {
        if ($method === 'zasilkovna_pickup' && $this->packeta->isConfigured()) {
            return array_replace($delivery, $this->packeta->verify((string) ($delivery['pickup_code'] ?? '')));
        }
        if ($method === 'ppl_pickup' && $this->ppl->isConfigured()) {
            return array_replace($delivery, $this->ppl->selection(
                (string) ($delivery['pickup_code'] ?? ''), (string) ($delivery['pickup_point'] ?? ''),
                (string) ($delivery['pickup_address'] ?? ''), (string) ($delivery['country'] ?? '')
            ));
        }
        if ($method === 'gls_pickup') {
            return array_replace($delivery, $this->gls->selection(
                (string) ($delivery['pickup_code'] ?? ''), (string) ($delivery['pickup_point'] ?? ''),
                (string) ($delivery['pickup_address'] ?? ''), (string) ($delivery['country'] ?? '')
            ));
        }
        if ($method === 'balikovna_pickup') {
            return array_replace($delivery, $this->balikovna->selection(
                (string) ($delivery['pickup_code'] ?? ''), (string) ($delivery['pickup_point'] ?? ''),
                (string) ($delivery['pickup_address'] ?? ''),
                (string) ($delivery['pickup_postal_code'] ?? ''), 'BALIKOVNY'
            ));
        }
        return $delivery;
    }
}
