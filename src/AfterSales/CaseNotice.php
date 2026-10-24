<?php
declare(strict_types=1);

namespace SimpleStore\AfterSales;

use DateTimeImmutable;
use DateTimeZone;
use SimpleStore\Accounting\MailContent;
use SimpleStore\Accounting\EmailLayout;

/** A printable receipt and an HTML/plain-text durable email share the same facts. */
final class CaseNotice
{
    public static function receipt(array $case, string $url = '', array $configuration = []): array
    {
        $copy = MailContent::resolvedCopy($configuration);
        $withdrawal = $case['kind'] === 'withdrawal';
        $caseOptions = json_decode((string) ($case['item_options_json'] ?? '{}'), true);
        $service = ($caseOptions['Plnění'] ?? '') === 'Služba asistovaného nákupu';
        $statement = $copy[$service ? ($withdrawal ? 'case_service_withdrawal' : 'case_service_complaint')
            : ($withdrawal ? 'case_withdrawal_statement' : 'case_complaint_statement')];
        $message = MailContent::template($withdrawal ? 'withdrawal_receipt' : 'case_receipt', $configuration, self::variables($case, $copy));
        $facts = array_merge(self::sellerFacts($case, $copy), [
            [$copy['case_number'], (string) $case['case_number']],
            [$copy['case_submitted'], self::time((string) $case['submitted_at'])],
            [$copy['order'], (string) $case['order_number']],
            [$copy['case_item'], self::itemLabel($case)],
            [$copy['quantity'], (string) (int) $case['quantity']],
            [$copy['unit_price'], MailContent::money((int) $case['unit_price_czk'])],
            [$copy['customer_name'], (string) $case['customer_name']],
            [$copy['company'], (string) $case['customer_company']],
            [$copy['case_email'], (string) $case['customer_email']],
            [$copy['phone'], (string) $case['customer_phone']],
            [$copy['delivered_on'], empty($case['delivered_on']) ? '' : MailContent::date($case['delivered_on'])],
            [$copy[$withdrawal ? 'customer_message' : 'defect'], (string) ($case['description'] === '' ? $copy['no_message'] : $case['description'])],
            [$copy['requested_solution'], $copy['remedy_' . $case['requested_solution']] ?? (string) $case['requested_solution']],
        ]);
        $lines = array_merge([$statement, ''], EmailLayout::factLines($facts));
        $html = EmailLayout::notice($statement) . EmailLayout::facts($facts) . EmailLayout::actions([[$copy['button_case'], $url]]);
        if (EmailLayout::safeUrl($url) !== '') $lines[] = $copy['button_case'] . ': ' . $url;
        return EmailLayout::message($message['subject'], $message['subject'], $message['message'], $html, $lines, $configuration);
    }

    public static function event(array $case, string $message, string $url = '', array $configuration = []): array
    {
        $copy = MailContent::resolvedCopy($configuration);
        $template = MailContent::template('case_event', $configuration, self::variables($case, $copy));
        $facts = array_merge(self::sellerFacts($case, $copy), [
            [$copy['case_number'], (string) $case['case_number']],
            [$copy['order'], (string) $case['order_number']],
            [$copy['case_item'], (int) $case['quantity'] . ' × ' . self::itemLabel($case)],
            [$copy['status'], $copy['case_status_' . $case['status']] ?? $case['status']],
            [$copy['resolution'], $copy['remedy_' . ($case['resolution_type'] ?? '')] ?? ''],
            [$copy['repair_duration'], (string) ($case['repair_duration'] ?? '')],
            [$copy['resolved_at'], $case['resolved_at'] === null ? '' : self::time((string) $case['resolved_at'])],
        ]);
        if ($case['refunded_at'] !== null) {
            $facts[] = [$copy['refund_amount'], MailContent::money((int) $case['refund_amount_czk'])];
            $facts[] = [$copy['refunded_at'], self::time((string) $case['refunded_at'])];
        }
        $lines = array_merge([$copy['case_message'], $message, ''], EmailLayout::factLines($facts));
        $html = EmailLayout::heading($copy['case_message']) . EmailLayout::paragraph($message) . EmailLayout::facts($facts) .
            EmailLayout::actions([[$copy['button_case'], $url]]);
        if (EmailLayout::safeUrl($url) !== '') $lines[] = $copy['button_case'] . ': ' . $url;
        return EmailLayout::message($template['subject'], $template['subject'], $template['message'], $html, $lines, $configuration);
    }

    private static function variables(array $case, array $copy): array
    {
        return ['case_number' => (string) $case['case_number'], 'order_number' => (string) $case['order_number'],
            'customer_name' => (string) ($case['customer_name'] ?? ''),
            'case_status' => $copy['case_status_' . ($case['status'] ?? '')] ?? ''];
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

    private static function sellerFacts(array $case, array $copy): array
    {
        $seller = json_decode((string) ($case['seller_json'] ?? ''), true);
        if (!is_array($seller)) return [];
        return [
            [$copy['seller'], (string) ($seller['name'] ?? '')],
            [$copy['ico'], (string) ($seller['ico'] ?? '')],
            [$copy['seller_address'], trim((string) ($seller['street'] ?? '') . ', ' .
                (string) ($seller['postal_code'] ?? '') . ' ' . (string) ($seller['city'] ?? ''), ', ')],
            [$copy['seller_contact'], (string) ($seller['email'] ?? '')],
        ];
    }
}
