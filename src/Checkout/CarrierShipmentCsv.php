<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use RuntimeException;

/** UTF-8 CSV for a configurable import profile in the carrier's own portal. */
final class CarrierShipmentCsv
{
    public static function export(array $draft): string
    {
        $method = $draft['method'] ?? '';
        if ($method === 'balikovna_pickup') {
            // For the NB product, the destination field is the point ID, not its physical ZIP.
            $columns = ['Reference', 'Typ zásilky', 'Příjmení/Název', 'PSČ', 'Obec',
                'Mobil', 'E-mail', 'Hmotnost (kg)'];
            $row = [$draft['order_number'], 'NB', $draft['recipient'],
                $draft['pickup_code'], $draft['city'], $draft['phone'],
                $draft['email'], $draft['weight_kg']];
        } elseif (in_array($method, ['gls_pickup', 'gls_home'], true)) {
            $pickup = $method === 'gls_pickup';
            $columns = ['Reference', 'Recipient name', 'Contact person', 'Street',
                'City', 'ZIP', 'Country', 'Phone', 'Email', 'Weight (kg)', 'Services'];
            $row = [$draft['order_number'], $pickup ? $draft['pickup_point'] : $draft['recipient'],
                $draft['recipient'], $draft['street'], $draft['city'], $draft['postal_code'],
                'CZ', $draft['phone'], $draft['email'], $draft['weight_kg'],
                $pickup ? 'PSD(' . $draft['pickup_code'] . ')' : ''];
        } else {
            throw new InvalidArgumentException('Tuto dopravu nelze exportovat.');
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
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $columns, ';', '"', '');
            fputcsv($file, $row, ';', '"', '');
            rewind($file);
            $data = stream_get_contents($file);
            if ($data === false) throw new RuntimeException('Soubor CSV nelze načíst.');
            return $data;
        } finally {
            fclose($file);
        }
    }
}
