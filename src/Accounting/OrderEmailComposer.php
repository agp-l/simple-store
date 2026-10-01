<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

/** Safe HTML and text alternatives built from immutable order data and editable copy. */
final class OrderEmailComposer
{
    public static function compose(string $event, array $order, array $template,
        array $tracking = [], string $orderUrl = ''): array
    {
        $shipping = $order['shipping'] ?? [];
        if (!is_array($shipping)) $shipping = [];
        $number = (string) ($order['order_number'] ?? '');
        $name = (string) ($shipping['recipient'] ?? $shipping['name'] ?? '');
        $carrier = (string) ($shipping['label'] ?? 'dopravce');
        $total = number_format((int) ($order['total_czk'] ?? 0), 0, ',', ' ') . ' Kč';
        $replace = ['{order_number}' => $number, '{customer_name}' => $name,
            '{total}' => $total, '{carrier}' => $carrier];
        $subject = strtr($template['subject'], $replace);
        $intro = strtr($template['message'], $replace);
        $lines = [trim($intro), '', 'Objednávka ' . $number, 'Stav: ' .
            (MailSettingsRepository::EVENTS[$event]['label'] ?? 'Objednávka'), 'Doprava: ' . $carrier];
        $facts = ['Stav' => MailSettingsRepository::EVENTS[$event]['label'] ?? 'Objednávka',
            'Doprava' => $carrier];
        if ($event === 'order') {
            $lines[] = '';
            $lines[] = 'Položky:';
            foreach ($order['items'] ?? [] as $item) {
                $lines[] = (int) ($item['quantity'] ?? 0) . ' × ' . (string) ($item['name'] ?? '') . ' — ' .
                    number_format((int) ($item['quantity'] ?? 0) * (int) ($item['unit_price_czk'] ?? 0),
                        0, ',', ' ') . ' Kč';
            }
            $lines[] = 'Doprava: ' . number_format((int) ($order['shipping_czk'] ?? 0), 0, ',', ' ') . ' Kč';
            $lines[] = 'Celkem: ' . $total;
            $facts['Celkem'] = $total;
            $method = (string) ($order['payment_method'] ?? '');
            if ($method === 'bank_transfer') {
                $bank = is_array($order['payment_details'] ?? null) ? $order['payment_details'] : [];
                $lines[] = '';
                $lines[] = 'Platba bankovním převodem';
                $lines[] = 'Číslo účtu: ' . (string) ($bank['account_display'] ?? '');
                $lines[] = 'Variabilní symbol: ' . (string) ($order['variable_symbol'] ?? '');
                $lines[] = 'Splatnost: ' . (string) ($order['payment_due_at'] ?? '');
                $facts['Platba'] = 'Bankovní převod';
                $facts['Číslo účtu'] = (string) ($bank['account_display'] ?? '');
                $facts['Variabilní symbol'] = (string) ($order['variable_symbol'] ?? '');
                $facts['Splatnost'] = (string) ($order['payment_due_at'] ?? '');
            } else {
                $gateway = ['comgate' => 'Comgate', 'gopay' => 'GoPay', 'btcpay' => 'BTCPay Server'][$method] ?? 'online';
                $lines[] = '';
                $lines[] = 'Zvolená platba: online přes ' . $gateway . '.';
                $lines[] = 'O výsledku platby rozhoduje potvrzení brány.';
                $facts['Platba'] = 'Online přes ' . $gateway;
            }
        }
        $trackingNumber = trim((string) ($tracking['number'] ?? ''));
        $trackingUrl = (string) ($tracking['url'] ?? '');
        if (in_array($event, ['shipped', 'tracking'], true)) {
            if ($trackingNumber !== '') {
                $lines[] = 'Číslo zásilky: ' . $trackingNumber;
                $facts['Číslo zásilky'] = $trackingNumber;
            }
            if ($trackingUrl !== '') {
                $lines[] = 'Sledování zásilky: ' . $trackingUrl;
            }
            if ($trackingNumber === '' && $trackingUrl === '') {
                $lines[] = 'Dopravce zatím nepřidělil číslo zásilky. Jakmile jej budeme mít, pošleme doplnění.';
            }
        }
        if ($orderUrl !== '') {
            $lines[] = 'Stav objednávky a případné opakování platby: ' . $orderUrl;
        }
        $lines[] = '';
        $lines[] = 'Děkujeme, tým dobrodruzi.cz';
        $text = implode("\n", $lines) . "\n";
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $factsHtml = '';
        foreach ($facts as $label => $value) {
            if ($value === '') continue;
            $factsHtml .= '<tr><th align="left" style="padding:9px 12px;color:#5f6b62;font-weight:500;border-bottom:1px solid #e5ebe2">' .
                $escape($label) . '</th><td align="right" style="padding:9px 12px;border-bottom:1px solid #e5ebe2">' .
                $escape($value) . '</td></tr>';
        }
        $itemsHtml = '';
        if ($event === 'order') {
            foreach ($order['items'] ?? [] as $item) {
                $itemsHtml .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #e5ebe2">' .
                    $escape((int) ($item['quantity'] ?? 0) . ' × ' . (string) ($item['name'] ?? '')) .
                    '</td><td align="right" style="padding:8px 12px;border-bottom:1px solid #e5ebe2">' .
                    $escape(number_format((int) ($item['quantity'] ?? 0) * (int) ($item['unit_price_czk'] ?? 0),
                        0, ',', ' ') . ' Kč') . '</td></tr>';
            }
        }
        $actions = '';
        foreach (['Sledovat zásilku' => $trackingUrl, 'Zobrazit objednávku' => $orderUrl] as $label => $url) {
            if ($url !== '') $actions .= '<p><a href="' . $escape($url) .
                '" style="display:inline-block;background:#426f40;color:#fff;padding:12px 17px;border-radius:4px;text-decoration:none;font-weight:700">' .
                $escape($label) . '</a></p>';
        }
        $note = in_array($event, ['shipped', 'tracking'], true) && $trackingNumber === '' && $trackingUrl === ''
            ? '<p style="color:#5f6b62">Dopravce zatím nepřidělil sledovací údaje. Pošleme je, jakmile je budeme mít.</p>' : '';
        $html = '<!doctype html><html lang="cs"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width"></head>' .
            '<body style="margin:0;padding:24px 12px;background:#f2f5ef;color:#252927;font-family:Arial,sans-serif;line-height:1.55">' .
            '<div style="max-width:600px;margin:auto;background:#fff;border:1px solid #dde4da;border-radius:6px;overflow:hidden">' .
            '<div style="background:#263a2b;color:#fff;padding:22px 26px;font-size:22px;font-weight:700">dobrodruzi<span style="color:#bde18d">.cz</span></div>' .
            '<div style="padding:24px 26px"><p style="margin:0 0 5px;color:#478444;font-size:12px;font-weight:700;text-transform:uppercase">' .
            $escape(MailSettingsRepository::EVENTS[$event]['label'] ?? 'Objednávka') . '</p>' .
            '<h1 style="font-size:24px;line-height:1.25;margin:0 0 16px">Objednávka ' . $escape($number) . '</h1>' .
            '<p style="white-space:pre-line">' . nl2br($escape($intro)) . '</p>' .
            '<table role="presentation" style="width:100%;border-collapse:collapse;margin:22px 0;font-size:14px">' . $factsHtml . '</table>' .
            ($itemsHtml === '' ? '' : '<h2 style="font-size:16px">Objednané zboží</h2><table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px">' .
                $itemsHtml . '</table><p style="text-align:right">Doprava: ' .
                $escape(number_format((int) ($order['shipping_czk'] ?? 0), 0, ',', ' ') . ' Kč') . '</p>') .
            $note . $actions . '<p style="margin-top:26px;color:#5f6b62;font-size:13px">Děkujeme, tým dobrodruzi.cz</p></div></div>' .
            '<p style="text-align:center;color:#5f6b62;font-size:12px">Tato zpráva se týká vaší objednávky. Můžete na ni odpovědět.</p></body></html>';
        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }
}
