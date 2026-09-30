<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Durable, idempotent notifications; a failed mail() never rolls back an order. */
final class OrderMailQueue
{
    private $transport;

    public function __construct(private MeekroDB $db, ?callable $transport = null)
    {
        $this->transport = $transport ?? static fn (string $to, string $subject,
            string $body, string $headers): bool => @mail($to, $subject, $body, $headers);
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_mail_outbox'
        ) > 0;
    }

    public function enqueueOrder(array $order, string $orderUrl = ''): ?int
    {
        if (!$this->installed() || ($order['payment_method'] ?? '') === 'test') return null;
        $lines = ['Děkujeme za objednávku ' . $order['order_number'] . '.', '', 'Položky:'];
        foreach (($order['items'] ?? []) as $item) {
            $lines[] = (int) $item['quantity'] . ' × ' . $item['name'] . ' — ' .
                ((int) $item['quantity'] * (int) $item['unit_price_czk']) . ' Kč';
        }
        $lines[] = 'Doprava: ' . (int) ($order['shipping_czk'] ?? 0) . ' Kč';
        $lines[] = 'Celkem: ' . (int) $order['total_czk'] . ' Kč';
        if (($order['payment_method'] ?? '') === 'bank_transfer') {
            $bank = $order['payment_details'] ?? [];
            $lines[] = '';
            $lines[] = 'Platba bankovním převodem';
            $lines[] = 'Číslo účtu: ' . ($bank['account_display'] ?? '');
            $lines[] = 'Variabilní symbol: ' . ($order['variable_symbol'] ?? '');
            $lines[] = 'Splatnost: ' . ($order['payment_due_at'] ?? '');
        } elseif (in_array($order['payment_method'] ?? '', ['comgate', 'gopay'], true)) {
            $gateway = $order['payment_method'] === 'gopay' ? 'GoPay' : 'Comgate';
            $lines[] = '';
            $lines[] = 'Zvolená platba: online přes ' . $gateway . '.';
            $lines[] = 'O výsledku platby rozhoduje potvrzení brány.';
            if ($orderUrl !== '') {
                if (filter_var($orderUrl, FILTER_VALIDATE_URL) === false ||
                    !str_starts_with($orderUrl, 'https://') || strlen($orderUrl) > 1000) {
                    throw new InvalidArgumentException('Neplatný odkaz na objednávku.');
                }
                $lines[] = 'Stav objednávky a případné opakování platby: ' . $orderUrl;
            }
        }
        $lines[] = '';
        $lines[] = 'Doprava: ' . ($order['shipping']['label'] ?? '');
        return $this->enqueue('order:' . (int) $order['id'], (int) $order['id'],
            (string) $order['customer_email'], 'Potvrzení objednávky ' . $order['order_number'],
            implode("\n", $lines) . "\n");
    }

    public function enqueueInvoice(array $invoice): int
    {
        $seller = $invoice['seller'];
        $buyer = $invoice['buyer'];
        $lines = ['Faktura ' . $invoice['document_number'],
            'Datum vystavení: ' . $invoice['issue_date'],
            'Objednávka: ' . $invoice['order_number'], '',
            'Dodavatel: ' . $seller['name'], 'IČO: ' . $seller['ico'],
            $seller['street'] . ', ' . $seller['postal_code'] . ' ' . $seller['city'],
            'Odběratel: ' . $buyer['name'],
            trim($buyer['street'] . ', ' . $buyer['postal_code'] . ' ' . $buyer['city'], ', '), '',
            'Položky:'];
        foreach ($invoice['items'] as $item) {
            $lines[] = (int) $item['quantity'] . ' × ' . $item['name'] . ' (' .
                (int) $item['unit_price_czk'] . ' Kč/ks) — ' .
                ((int) $item['quantity'] * (int) $item['unit_price_czk']) . ' Kč';
        }
        $lines[] = 'Doprava: ' . (int) $invoice['shipping_czk'] . ' Kč';
        $lines[] = 'Celkem: ' . (int) $invoice['total_czk'] . ' Kč';
        $lines[] = match ($invoice['payment_method'] ?? 'bank_transfer') {
            'comgate' => 'Uhrazeno online přes Comgate.',
            'gopay' => 'Platba online přes GoPay byla při vystavení faktury potvrzená.',
            default => 'Uhrazeno bankovním převodem · VS: ' . ($invoice['variable_symbol'] ?? ''),
        };
        $lines[] = 'Nejsem plátce DPH.';
        $subject = 'Faktura ' . $invoice['document_number'];
        $body = implode("\n", $lines) . "\n";
        $id = $this->enqueue('invoice:' . (int) $invoice['id'], (int) $invoice['order_id'],
            (string) $buyer['email'], $subject, $body);
        // A corrected number must also reach the queued message. Never rewrite sent mail.
        $this->db->query('UPDATE shop_mail_outbox SET subject=%s, body_text=%s
            WHERE id=%i AND state IN (%s,%s)', $subject, $body, $id, 'queued', 'failed');
        return $id;
    }

    public function dispatch(int $id, string $sender): bool
    {
        if ($id < 1 || filter_var($sender, FILTER_VALIDATE_EMAIL) === false ||
            preg_match('/[\r\n]/', $sender) === 1) {
            throw new InvalidArgumentException('Pro odesílání vyplň e-mail odesílatele v účetnictví.');
        }
        $this->db->startTransaction();
        try {
            $row = $this->db->queryFirstRow(
                'SELECT * FROM shop_mail_outbox WHERE id=%i LIMIT 1 FOR UPDATE', $id);
            if ($row === null || !in_array($row['state'], ['queued', 'failed'], true)) {
                $this->db->commit();
                return false;
            }
            $this->db->query('UPDATE shop_mail_outbox SET state=%s, attempts=attempts+1
                WHERE id=%i AND state=%s', 'sending', $id, $row['state']);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        try {
            $subject = '=?UTF-8?B?' . base64_encode($row['subject']) . '?=';
            $headers = 'From: ' . $sender . "\r\n" .
                "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: 8bit";
            $sent = (bool) ($this->transport)($row['recipient_email'], $subject,
                $row['body_text'], $headers);
        } catch (Throwable $error) {
            error_log('Store mail transport failed: ' . $error->getMessage());
            $sent = false;
        }
        $this->db->query('UPDATE shop_mail_outbox SET state=%s, last_error=%s,
            sent_at=IF(%i=1, UTC_TIMESTAMP(), NULL) WHERE id=%i AND state=%s',
            $sent ? 'sent' : 'failed', $sent ? null : 'Poštovní server zprávu nepřijal.',
            $sent ? 1 : 0, $id, 'sending');
        if ($sent && str_starts_with($row['event_key'], 'invoice:')) {
            $this->db->query('UPDATE shop_invoices SET emailed_at=UTC_TIMESTAMP()
                WHERE id=%i AND emailed_at IS NULL', (int) substr($row['event_key'], 8));
        }
        return $sent;
    }

    public function recent(): array
    {
        return $this->db->query('SELECT id, order_id, event_key, recipient_email, subject,
                state, attempts, last_error, sent_at, created_at
            FROM shop_mail_outbox ORDER BY id DESC LIMIT %i', 100);
    }

    private function enqueue(string $key, int $orderId, string $email,
        string $subject, string $body): int
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            strlen($subject) > 190 || strlen($body) > 50000) {
            throw new InvalidArgumentException('E-mailovou zprávu nelze připravit.');
        }
        $this->db->query('INSERT IGNORE INTO shop_mail_outbox
            (event_key, order_id, recipient_email, subject, body_text) VALUES (%s, %i, %s, %s, %s)',
            $key, $orderId, $email, $subject, $body);
        $id = $this->db->queryFirstField('SELECT id FROM shop_mail_outbox WHERE event_key=%s', $key);
        if ((int) $id < 1) throw new InvalidArgumentException('Zprávu se nepodařilo uložit.');
        return (int) $id;
    }
}
