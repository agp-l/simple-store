<?php
declare(strict_types=1);

namespace SimpleStore\AfterSales;

use DateTimeImmutable;
use DateTimeZone;

/** A printable receipt and an HTML/plain-text durable email share the same facts. */
final class CaseNotice
{
    public static function receipt(array $case, string $url = ''): array
    {
        $withdrawal = $case['kind'] === 'withdrawal';
        $title = $withdrawal ? 'Potvrzení odstoupení od smlouvy' : 'Potvrzení přijetí reklamace';
        $statement = $withdrawal
            ? 'Zákazník odstoupil od kupní smlouvy v rozsahu níže uvedeného zboží.'
            : 'Zákazník uplatnil reklamaci zboží.';
        $facts = self::sellerFacts($case) + [
            'Číslo případu' => (string) $case['case_number'],
            'Datum a čas podání' => self::time((string) $case['submitted_at']),
            'Objednávka' => (string) $case['order_number'],
            'Zboží' => self::itemLabel($case),
            'Množství' => (int) $case['quantity'] . ' ks',
            'Cena za kus při objednání' => number_format((int) $case['unit_price_czk'], 0, ',', ' ') . ' Kč',
            'Jméno' => (string) $case['customer_name'],
            'Společnost' => (string) $case['customer_company'],
            'E-mail pro vyřízení' => (string) $case['customer_email'],
            'Telefon' => (string) $case['customer_phone'],
            'Datum převzetí sdělené zákazníkem' => (string) ($case['delivered_on'] ?? ''),
            $withdrawal ? 'Sdělení zákazníka' : 'Popis vady' => (string) ($case['description'] === ''
                ? 'Bez dalšího sdělení.' : $case['description']),
            'Požadované řešení' => CaseRepository::REMEDIES[$case['requested_solution']] ?? (string) $case['requested_solution'],
        ];
        $intro = [$statement, '', $title];
        foreach ($facts as $label => $value) {
            if ($value !== '') $intro[] = $label . ': ' . $value;
        }
        if ($url !== '') $intro[] = 'Stav a tisk potvrzení: ' . $url;
        $intro[] = '';
        $intro[] = 'O dalším postupu vás budeme informovat.';
        return [
            'subject' => $title . ' ' . $case['case_number'],
            'text' => implode("\n", $intro) . "\n",
            'html' => self::html($title, $statement, $facts, $url),
        ];
    }

    public static function event(array $case, string $message, string $url = ''): array
    {
        $status = CaseRepository::STATUSES[$case['status']] ?? 'Změna stavu';
        $title = 'Případ ' . $case['case_number'] . ': ' . $status;
        $facts = self::sellerFacts($case) + [
            'Číslo případu' => (string) $case['case_number'],
            'Objednávka' => (string) $case['order_number'],
            'Zboží' => (int) $case['quantity'] . ' × ' . self::itemLabel($case),
            'Stav' => $status,
            'Způsob vyřízení' => CaseRepository::REMEDIES[$case['resolution_type'] ?? ''] ?? '',
            'Doba opravy' => (string) ($case['repair_duration'] ?? ''),
            'Datum vyřízení' => $case['resolved_at'] === null ? '' : self::time((string) $case['resolved_at']),
        ];
        if ($case['refunded_at'] !== null) {
            $facts['Vrácená částka'] = number_format((int) $case['refund_amount_czk'], 0, ',', ' ') . ' Kč';
            $facts['Vrácení zaznamenáno'] = self::time((string) $case['refunded_at']);
        }
        $lines = [$title, '', $message, ''];
        foreach ($facts as $label => $value) if ($value !== '') $lines[] = $label . ': ' . $value;
        if ($url !== '') $lines[] = 'Detail případu: ' . $url;
        return ['subject' => $title, 'text' => implode("\n", $lines) . "\n",
            'html' => self::html($title, $message, $facts, $url)];
    }

    /** The timestamp stored in the database is UTC; always label the displayed zone. */
    public static function time(string $utc): string
    {
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Prague'))->format('j. n. Y H:i') . ' (Praha)';
        } catch (\Throwable $ignored) {
            return $utc . ' UTC';
        }
    }

    public static function publicUrl(array $case, string $base): string
    {
        $base = rtrim($base, '/');
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL) ||
            parse_url($base, PHP_URL_SCHEME) !== 'https' ||
            parse_url($base, PHP_URL_USER) !== null || parse_url($base, PHP_URL_QUERY) !== null ||
            parse_url($base, PHP_URL_FRAGMENT) !== null ||
            !preg_match('/^[a-f0-9]{64}$/D', (string) ($case['case_token'] ?? ''))) return '';
        return $base . '/support.php?case=' . $case['case_token'];
    }

    public static function itemLabel(array $case): string
    {
        $name = (string) $case['item_name'];
        $options = json_decode((string) ($case['item_options_json'] ?? ''), true);
        if (!is_array($options)) return $name;
        foreach ($options as $label => $value) {
            if (is_string($label) && is_scalar($value)) {
                $name .= ' · ' . $label . ': ' . (string) $value;
            }
        }
        return $name;
    }

    public static function sellerReady(array $case): bool
    {
        $seller = json_decode((string) ($case['seller_json'] ?? ''), true);
        return is_array($seller) &&
            trim((string) ($seller['name'] ?? '')) !== '' &&
            trim((string) ($seller['ico'] ?? '')) !== '' &&
            trim((string) ($seller['street'] ?? '')) !== '' &&
            trim((string) ($seller['city'] ?? '')) !== '' &&
            trim((string) ($seller['postal_code'] ?? '')) !== '';
    }

    private static function sellerFacts(array $case): array
    {
        $seller = json_decode((string) ($case['seller_json'] ?? ''), true);
        if (!is_array($seller)) return [];
        return [
            'Prodávající' => (string) ($seller['name'] ?? ''),
            'IČO' => (string) ($seller['ico'] ?? ''),
            'Adresa prodávajícího' => trim((string) ($seller['street'] ?? '') . ', ' .
                (string) ($seller['postal_code'] ?? '') . ' ' . (string) ($seller['city'] ?? ''), ', '),
            'Kontakt prodávajícího' => (string) ($seller['email'] ?? ''),
        ];
    }

    private static function html(string $title, string $message, array $facts, string $url): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        foreach ($facts as $label => $value) {
            if ($value === '') continue;
            $rows .= '<tr><th align="left" style="padding:12px 14px;border-bottom:1px solid #e7eee6;color:#526354;width:42%;font-weight:500;vertical-align:top">' .
                $e($label) . '</th><td style="padding:12px 14px;border-bottom:1px solid #e7eee6;white-space:pre-line">' .
                $e($value) . '</td></tr>';
        }
        $link = $url === '' ? '' : '<p style="margin:26px 0"><a href="' . $e($url) .
            '" style="display:inline-block;background:#375f39;color:#fff;text-decoration:none;padding:13px 18px;border-radius:5px;font-weight:700">Zobrazit případ</a></p>';
        return '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>' .
            '<body style="margin:0;background:#f4f6f0;color:#1b2921;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6">' .
            '<div style="max-width:620px;margin:0 auto;padding:24px 12px"><div style="padding:22px 28px;background:#254b32;color:#fff;border-radius:7px 7px 0 0">' .
            '<strong style="font-size:21px;letter-spacing:.02em">dobrodruzi.cz</strong><div style="color:#d9ead7">Péče o zákazníky</div></div>' .
            '<div style="background:#fff;padding:28px;border:1px solid #e5ebe2;border-top:0;border-radius:0 0 7px 7px">' .
            '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.25">' . $e($title) . '</h1>' .
            '<p style="white-space:pre-line;margin:0 0 22px">' . $e($message) . '</p>' .
            '<table role="presentation" style="width:100%;border-collapse:collapse;background:#f9fbf7">' . $rows . '</table>' .
            $link . '<p style="font-size:13px;color:#57645b">Na tuto zprávu můžete odpovědět, pokud potřebujete doplnit podklady.</p></div></div></body></html>';
    }
}
