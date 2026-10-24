<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

/** Invoice and account messages use the same copy settings and visual language as orders. */
final class ServiceEmailComposer
{
    public static function invoice(array $invoice, array $configuration = []): array
    {
        $copy = MailContent::resolvedCopy($configuration);
        $seller = $invoice['seller']; $buyer = $invoice['buyer'];
        $vars = ['invoice_number' => (string) $invoice['document_number'], 'order_number' => (string) ($invoice['order_number'] ?? ''),
            'customer_name' => (string) ($buyer['name'] ?? ''), 'total' => MailContent::money((int) $invoice['total_czk'])] + MailContent::variables($configuration);
        $message = MailContent::template('invoice', $configuration, $vars);
        $facts = [[$copy['issue_date'], MailContent::date((string) $invoice['issue_date'])], [$copy['order'], $vars['order_number']],
            [$copy['supplier'], (string) $seller['name']], [$copy['ico'], (string) $seller['ico']],
            [$copy['seller_address'], trim($seller['street'] . ', ' . $seller['postal_code'] . ' ' . $seller['city'], ', ')],
            [$copy['buyer'], (string) $buyer['name']], [$copy['address'], trim($buyer['street'] . ', ' . $buyer['postal_code'] . ' ' . $buyer['city'], ', ')]];
        $lines = EmailLayout::factLines($facts);
        $html = EmailLayout::facts($facts) . EmailLayout::heading($copy['items']) . OrderEmailComposer::itemsHtml($invoice['items'], $copy, true);
        $lines[] = ''; $lines[] = $copy['items'];
        foreach ($invoice['items'] as $item) {
            $lines[] = (int) $item['quantity'] . ' × ' . $item['name'] . ' — ' . MailContent::money((int) $item['quantity'] * (int) $item['unit_price_czk']);
            foreach (OrderEmailComposer::options($item) as $option) $lines[] = '  ' . $option;
        }
        $totals = [[$copy['shipping'], MailContent::money((int) $invoice['shipping_czk'])], [$copy['total'], $vars['total']]];
        $html .= EmailLayout::facts($totals);
        $lines = array_merge($lines, EmailLayout::factLines($totals));
        $principal = \SimpleStore\Checkout\AssistedPurchase::principal($invoice['items']);
        if ($principal > 0) {
            $note = MailContent::fill($copy['invoice_principal'], ['principal' => MailContent::money($principal),
                'paid_total' => MailContent::money((int) $invoice['total_czk'] + $principal)]);
            $lines[] = $note; $html .= EmailLayout::notice($note);
        }
        $method = (string) ($invoice['payment_method'] ?? 'bank_transfer');
        $paid = MailContent::fill($copy[in_array($method, ['comgate', 'gopay', 'btcpay'], true) ? 'invoice_online' : 'invoice_bank'],
            ['gateway' => ['comgate' => 'Comgate', 'gopay' => 'GoPay', 'btcpay' => 'BTCPay Server'][$method] ?? '',
                'variable_symbol' => (string) ($invoice['variable_symbol'] ?? '')]);
        $lines[] = ''; $lines[] = $paid; $lines[] = $copy['invoice_vat'];
        $html .= EmailLayout::paragraph($paid . "\n" . $copy['invoice_vat']);
        return EmailLayout::message($message['subject'], MailContent::fill($copy['invoice_heading'], $vars), $message['message'], $html, $lines, $configuration);
    }

    public static function passwordReset(string $role, string $url, array $configuration = []): array
    {
        $copy = MailContent::resolvedCopy($configuration);
        $message = MailContent::template($role === 'admin' ? 'admin_password_reset' : 'password_reset', $configuration);
        $url = EmailLayout::safeUrl($url);
        $expiry = MailContent::fill($copy['reset_expiry'], ['minutes' => '30']);
        $html = EmailLayout::actions([[$copy['button_reset'], $url]]) . EmailLayout::paragraph($expiry) .
            EmailLayout::notice($copy['reset_ignore']) . EmailLayout::paragraph($copy['reset_fallback']) .
            '<p style="font-size:13px;overflow-wrap:anywhere;word-break:break-all"><a href="' . EmailLayout::escape($url) .
            '" style="color:#254e3b">' . EmailLayout::escape($url) . '</a></p>';
        return EmailLayout::message($message['subject'], $copy['reset_heading'], $message['message'], $html,
            [$copy['button_reset'] . ': ' . $url, '', $expiry, $copy['reset_ignore']], $configuration);
    }

    public static function test(array $configuration = []): array
    {
        $message = MailContent::template('test', $configuration);
        return EmailLayout::message($message['subject'], $message['subject'], $message['message'], '', [], $configuration);
    }
}
