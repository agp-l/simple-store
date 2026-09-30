<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use RuntimeException;

/** Exact 17-column, headerless GLS e-Balík default import supplied by the portal. */
final class CarrierShipmentCsv
{
    public static function export(array $draft): string
    {
        $method = $draft['method'] ?? '';
        if (in_array($method, ['gls_pickup', 'gls_home'], true)) {
            $pickup = $method === 'gls_pickup';
            // 1 dimensions, 2 weight, 3-4 recipient, 5 company, 6-10 address,
            // 11-12 contact, 13 note, 14 COD, 15 variable symbol,
            // 16 insurance, 17 ParcelShopDelivery point ID.
            $row = ['', $draft['weight_kg'], $draft['first_name'], $draft['surname'], '',
                $draft['street'], $draft['house_number'], $draft['city'], $draft['postal_code'],
                'CZ', $draft['phone'], $draft['email'], '', '',
                $draft['variable_symbol'], '', $pickup ? $draft['pickup_code'] : ''];
        } else {
            throw new InvalidArgumentException('CSV je dostupné pouze pro GLS e-Balík.');
        }

        // Even saved snapshots must not create spreadsheet formulas when opened in Excel.
        foreach ($row as $cell) {
            if (!is_string($cell) || preg_match('//u', $cell) !== 1 ||
                preg_match('/[\x00-\x1f\x7f]/', $cell) === 1 ||
                preg_match('/^\s*[=+@-]/u', $cell) === 1) {
                throw new InvalidArgumentException('Podklady obsahují nepovolenou hodnotu pro CSV.');
            }
        }
        $file = fopen('php://temp', 'w+');
        if ($file === false) throw new RuntimeException('Soubor CSV nelze připravit.');
        try {
            if (fputcsv($file, $row, ';', '"', '') === false) {
                throw new RuntimeException('Soubor CSV nelze zapsat.');
            }
            rewind($file);
            $data = stream_get_contents($file);
            if ($data === false) throw new RuntimeException('Soubor CSV nelze načíst.');
            return $data;
        } finally {
            fclose($file);
        }
    }
}
