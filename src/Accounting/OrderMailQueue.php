<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Checkout\OrderTrackingRepository;
use RuntimeException;
use Throwable;

/** Durable, idempotent notifications; a failed transport never rolls back an order. */
final class OrderMailQueue
{
    public const MAX_AUTO_ATTEMPTS = 5;
    private const RETRY_BASE_SECONDS = 300;
    private const STALE_SENDING_MINUTES = 10;

    private $transport;
    private MailSettingsRepository $settings;

    public function __construct(private MeekroDB $db, ?callable $transport = null)
    {
        $this->settings = new MailSettingsRepository($db);
        $this->transport = $transport;
    }

    public function installed(): bool
    {
        if ((int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_mail_outbox'
        ) === 0) return false;
        foreach (['attempted_at', 'next_attempt_at'] as $column) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
                'shop_mail_outbox', $column
            ) === 0) return false;
        }
        return true;
    }

    public function enqueueOrder(array $order, string $orderUrl = ''): ?int
    {
        if (!$this->installed() || ($order['payment_method'] ?? '') === 'test' ||
            ($order['status'] ?? '') === 'cancelled') return null;
        $config = $this->settings->load();
        if (!$config['settings']['automatic_enabled'] || !$config['templates']['order']['enabled']) return null;
        $message = OrderEmailComposer::compose('order', $order, $config['templates']['order'], [],
            $this->orderUrl($order, $config['settings'], $orderUrl));
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId < 1) throw new InvalidArgumentException('Neplatná objednávka pro potvrzení e-mailem.');
        // Checkout and cancellation both lock the order before its outbox row. A delayed
        // checkout must not queue payment instructions after the order was cancelled.
        $this->db->startTransaction();
        try {
            $current = $this->db->queryFirstRow(
                'SELECT status, payment_status FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId
            );
            if ($current === null || $current['status'] !== 'new' ||
                $current['payment_status'] !== 'pending') {
                $this->db->commit();
                return null;
            }
            $id = $this->enqueue('order:' . $orderId, $orderId,
                (string) $order['customer_email'], $message['subject'], $message['text'], $message['html']);
            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    /** Queue a stage once per order; repeated callbacks or clicks cannot send duplicates. */
    public function enqueueStage(array $order, string $stage, string $eventKey = ''): ?int
    {
        if (!$this->installed() || ($order['payment_method'] ?? '') === 'test' ||
            !isset(MailSettingsRepository::EVENTS[$stage])) return null;
        $config = $this->settings->load();
        if (!$config['settings']['automatic_enabled'] || !$config['templates'][$stage]['enabled']) return null;
        $tracking = in_array($stage, ['shipped', 'tracking'], true)
            ? (new OrderTrackingRepository($this->db))->forOrder((int) $order['id']) : [];
        if ($stage === 'tracking' && ($tracking['number'] ?? '') === '' && ($tracking['url'] ?? '') === '') return null;
        $message = OrderEmailComposer::compose($stage, $order, $config['templates'][$stage], $tracking,
            $this->orderUrl($order, $config['settings']));
        $key = $eventKey !== '' ? $eventKey : $stage . ':' . (int) $order['id'];
        return $this->enqueue($key, (int) $order['id'], (string) $order['customer_email'],
            $message['subject'], $message['text'], $message['html']);
    }

    public function notifyStage(int $orderId, string $stage, string $legacySender = '', string $key = ''): void
    {
        $order = (new OrderRepository($this->db))->findById($orderId);
        if ($order === null) return;
        if ($stage === 'paid' && ($order['payment_status'] ?? '') !== 'paid' ||
            $stage !== 'paid' && $stage !== 'tracking' && $order['status'] !== $stage ||
            $stage === 'tracking' && !in_array($order['status'], ['shipped', 'completed'], true)) return;
        $id = $this->enqueueStage($order, $stage, $key);
        if ($id !== null && $this->sender($legacySender) !== '') $this->dispatch($id, $legacySender, true);
    }

    public function sender(string $legacySender = ''): string
    {
        return (string) ($this->settings->load($legacySender)['settings']['from_email'] ?? '');
    }

    public function automaticEnabled(): bool
    {
        return $this->settings->load()['settings']['automatic_enabled'] === true;
    }

    public function enqueueTest(string $email): int
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Zadej platný e-mail příjemce testu.');
        }
        $body = "Test e-mailového nastavení dobrodruzi.cz\n\nPokud tuto zprávu čtete, hosting zprávu přijal k odeslání.\n";
        return $this->enqueue('test:' . bin2hex(random_bytes(12)), 0, $email,
            'Test e-mailu dobrodruzi.cz', $body, self::simpleHtml($body));
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
            'btcpay' => 'Platba přes BTCPay Server byla při vystavení faktury potvrzená.',
            default => 'Uhrazeno bankovním převodem · VS: ' . ($invoice['variable_symbol'] ?? ''),
        };
        $lines[] = 'Nejsem plátce DPH.';
        $subject = 'Faktura ' . $invoice['document_number'];
        $body = implode("\n", $lines) . "\n";
        $id = $this->enqueue('invoice:' . (int) $invoice['id'],
            $invoice['order_id'] === null ? null : (int) $invoice['order_id'],
            (string) $buyer['email'], $subject, $body, self::simpleHtml($body));
        // A corrected number must also reach the queued message. Never rewrite sent mail.
        if ($this->htmlInstalled()) {
            $this->db->query('UPDATE shop_mail_outbox SET subject=%s, body_text=%s, body_html=%s
                WHERE id=%i AND state IN (%s,%s)', $subject, $body, self::simpleHtml($body), $id, 'queued', 'failed');
        } else {
            $this->db->query('UPDATE shop_mail_outbox SET subject=%s, body_text=%s
                WHERE id=%i AND state IN (%s,%s)', $subject, $body, $id, 'queued', 'failed');
        }
        return $id;
    }

    /** Explicit calls may retry immediately, even after the automatic attempt limit. */
    public function dispatch(int $id, string $sender = '', bool $automatic = false): bool
    {
        if (!$this->installed()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky e-mailů.');
        $config = $this->settings->load($sender)['settings'];
        $sender = (string) $config['from_email'];
        if ($id < 1 || filter_var($sender, FILTER_VALIDATE_EMAIL) === false ||
            preg_match('/[\r\n]/', $sender) === 1) {
            throw new InvalidArgumentException('Pro odesílání vyplň e-mail odesílatele v Nastavení obchodu → E-maily.');
        }
        // Read the immutable event key before locking. For the original confirmation,
        // lock order first, outbox second, just like cancellation and enqueueOrder.
        $hint = $this->db->queryFirstRow(
            'SELECT event_key, order_id FROM shop_mail_outbox WHERE id=%i LIMIT 1', $id
        );
        if ($hint === null) return false;
        $orderId = preg_match('/^order:([1-9][0-9]*)$/D', (string) $hint['event_key'], $matches) === 1
            ? (int) $matches[1] : null;
        $this->db->startTransaction();
        try {
            $current = $orderId === null ? null : $this->db->queryFirstRow(
                'SELECT status, payment_status FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId
            );
            $row = $this->db->queryFirstRow(
                'SELECT *, (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP()) AS retry_due
                 FROM shop_mail_outbox WHERE id=%i LIMIT 1 FOR UPDATE', $id);
            if ($row === null || !in_array($row['state'], ['queued', 'failed'], true) ||
                ($automatic && ((int) $row['attempts'] >= self::MAX_AUTO_ATTEMPTS ||
                    (int) $row['retry_due'] !== 1))) {
                $this->db->commit();
                return false;
            }
            if ($orderId !== null && ($current === null || $current['status'] !== 'new' ||
                $current['payment_status'] !== 'pending')) {
                $this->db->query('UPDATE shop_mail_outbox SET state=%s,
                    next_attempt_at=NULL, last_error=%s WHERE id=%i AND state=%s',
                    'suppressed', 'Potvrzení již neodpovídá stavu objednávky.', $id, $row['state']);
                $this->db->commit();
                return false;
            }
            $this->db->query('UPDATE shop_mail_outbox SET state=%s, attempts=attempts+1,
                attempted_at=UTC_TIMESTAMP(), next_attempt_at=NULL
                WHERE id=%i AND state=%s', 'sending', $id, $row['state']);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        try {
            $subject = '=?UTF-8?B?' . base64_encode($row['subject']) . '?=';
            $name = trim((string) $config['from_name']);
            $headers = 'From: ' . ($name === '' ? '' : '=?UTF-8?B?' . base64_encode($name) . '?= ') .
                '<' . $sender . ">\r\n";
            if ($config['reply_to'] !== '') $headers .= 'Reply-To: ' . $config['reply_to'] . "\r\n";
            $boundary = 'simple-store-' . bin2hex(random_bytes(12));
            $headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"" . $boundary . '"';
            $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: base64\r\n\r\n" .
                chunk_split(base64_encode((string) $row['body_text']), 76, "\r\n") .
                "\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: base64\r\n\r\n" .
                chunk_split(base64_encode((string) ($row['body_html'] ?? self::simpleHtml((string) $row['body_text']))), 76, "\r\n") .
                "\r\n--{$boundary}--\r\n";
            $sent = $this->transport !== null
                ? (bool) ($this->transport)($row['recipient_email'], $subject, $body, $headers)
                : (new MailTransport($config))->send($row['recipient_email'], $subject, $body, $headers);
            $failure = 'Poštovní server zprávu nepřijal.';
        } catch (MailDeliveryUncertainException $error) {
            error_log('Store mail acceptance uncertain: ' . $error->getMessage());
            // Do not retry automatically: the remote server may already have the message.
            return false;
        } catch (Throwable $error) {
            error_log('Store mail transport failed: ' . $error->getMessage());
            $failure = substr($error->getMessage(), 0, 250);
            $sent = false;
        }
        $attempts = (int) $row['attempts'] + 1;
        $delay = self::RETRY_BASE_SECONDS * (2 ** min($attempts - 1, self::MAX_AUTO_ATTEMPTS - 1));
        $this->db->startTransaction();
        try {
            $this->db->query('UPDATE shop_mail_outbox SET state=%s, last_error=%s,
                sent_at=IF(%i=1, UTC_TIMESTAMP(), NULL),
                next_attempt_at=IF(%i=1, NULL, DATE_ADD(UTC_TIMESTAMP(), INTERVAL %i SECOND))
                WHERE id=%i AND state=%s',
                $sent ? 'sent' : 'failed', $sent ? null : $failure,
                $sent ? 1 : 0, $sent || $attempts >= self::MAX_AUTO_ATTEMPTS ? 1 : 0,
                $delay, $id, 'sending');
            if ($sent && str_starts_with($row['event_key'], 'invoice:')) {
                $this->db->query('UPDATE shop_invoices SET emailed_at=UTC_TIMESTAMP()
                    WHERE id=%i AND emailed_at IS NULL', (int) substr($row['event_key'], 8));
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            // SMTP acceptance is unknown if this transaction fails. Keep `sending` for review.
            throw $error;
        }
        return $sent;
    }

    /** One bounded cron pass. Row locks in dispatch protect against overlapping workers. */
    public function dispatchDue(int $limit = 20, string $sender = ''): array
    {
        if ($limit < 1 || $limit > 50) throw new InvalidArgumentException('Limit fronty musí být mezi 1 a 50.');
        if (!$this->installed()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky e-mailů.');
        if (filter_var($this->sender($sender), FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Vyplň platný e-mail odesílatele v Nastavení obchodu → E-maily.');
        }
        $rows = $this->db->query('SELECT id FROM shop_mail_outbox
            WHERE state IN (%s,%s) AND attempts < %i
              AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
            ORDER BY id LIMIT %i', 'queued', 'failed', self::MAX_AUTO_ATTEMPTS, $limit);
        $result = ['selected' => count($rows), 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            if ($this->dispatch((int) $row['id'], $sender, true)) $result['sent']++;
            else {
                $state = $this->db->queryFirstField('SELECT state FROM shop_mail_outbox WHERE id=%i', (int) $row['id']);
                $result[$state === 'failed' ? 'failed' : 'skipped']++;
            }
        }
        return $result;
    }

    /** Resolve an ambiguous crash only after checking the mail server's logs. */
    public function reconcileSending(int $id, string $outcome): void
    {
        if ($id < 1 || !in_array($outcome, ['accepted', 'not_accepted'], true) || !$this->installed()) {
            throw new InvalidArgumentException('Vyber zprávu a ověřený výsledek na poštovním serveru.');
        }
        $this->db->startTransaction();
        try {
            $row = $this->db->queryFirstRow('SELECT *,
                (COALESCE(attempted_at, created_at) <= UTC_TIMESTAMP() - INTERVAL ' .
                self::STALE_SENDING_MINUTES . ' MINUTE) AS stale_sending
                FROM shop_mail_outbox WHERE id=%i LIMIT 1 FOR UPDATE', $id);
            if ($row === null || $row['state'] !== 'sending' || (int) $row['stale_sending'] !== 1) {
                throw new InvalidArgumentException('Zpráva ještě není ve stavu k ručnímu dořešení.');
            }
            if ($outcome === 'accepted') {
                $this->db->query('UPDATE shop_mail_outbox SET state=%s, sent_at=UTC_TIMESTAMP(),
                    next_attempt_at=NULL, last_error=NULL WHERE id=%i AND state=%s',
                    'sent', $id, 'sending');
                if (str_starts_with($row['event_key'], 'invoice:')) {
                    $this->db->query('UPDATE shop_invoices SET emailed_at=UTC_TIMESTAMP()
                        WHERE id=%i AND emailed_at IS NULL', (int) substr($row['event_key'], 8));
                }
            } else {
                $this->db->query('UPDATE shop_mail_outbox SET state=%s,
                    last_error=%s, next_attempt_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL %i SECOND)
                    WHERE id=%i AND state=%s', 'failed',
                    'Ručně ověřeno: poštovní server zprávu nepřijal.', self::RETRY_BASE_SECONDS,
                    $id, 'sending');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function recent(): array
    {
        return $this->db->query('SELECT id, order_id, event_key, recipient_email, subject,
                state, attempts, last_error, attempted_at, next_attempt_at, sent_at, created_at,
                (state=%s AND COALESCE(attempted_at, created_at) <= UTC_TIMESTAMP() - INTERVAL ' .
                self::STALE_SENDING_MINUTES . ' MINUTE) AS stale_sending
            FROM shop_mail_outbox
            ORDER BY stale_sending DESC, (state=%s) DESC, id DESC LIMIT %i',
            'sending', 'failed', 100);
    }

    private function enqueue(string $key, ?int $orderId, string $email,
        string $subject, string $body, string $html): int
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            strlen($subject) > 190 || preg_match('/[\r\n]/', $subject) ||
            strlen($body) > 50000 || strlen($html) > 100000) {
            throw new InvalidArgumentException('E-mailovou zprávu nelze připravit.');
        }
        if (!$this->htmlInstalled()) {
            if ($orderId !== null && $orderId > 0) {
                $this->db->query('INSERT IGNORE INTO shop_mail_outbox
                    (event_key, order_id, recipient_email, subject, body_text) VALUES (%s, %i, %s, %s, %s)',
                    $key, $orderId, $email, $subject, $body);
            } else {
                $this->db->query('INSERT IGNORE INTO shop_mail_outbox
                    (event_key, order_id, recipient_email, subject, body_text) VALUES (%s, NULL, %s, %s, %s)',
                    $key, $email, $subject, $body);
            }
        } elseif ($orderId !== null && $orderId > 0) {
            $this->db->query('INSERT IGNORE INTO shop_mail_outbox
                (event_key, order_id, recipient_email, subject, body_text, body_html) VALUES (%s, %i, %s, %s, %s, %s)',
                $key, $orderId, $email, $subject, $body, $html);
        } else {
            $this->db->query('INSERT IGNORE INTO shop_mail_outbox
                (event_key, order_id, recipient_email, subject, body_text, body_html) VALUES (%s, NULL, %s, %s, %s, %s)',
                $key, $email, $subject, $body, $html);
        }
        $id = $this->db->queryFirstField('SELECT id FROM shop_mail_outbox WHERE event_key=%s', $key);
        if ((int) $id < 1) throw new InvalidArgumentException('Zprávu se nepodařilo uložit.');
        return (int) $id;
    }

    private function htmlInstalled(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
            'shop_mail_outbox', 'body_html') > 0;
    }

    private function orderUrl(array $order, array $settings, string $provided = ''): string
    {
        $url = $provided;
        if ($url === '' && $settings['public_base_url'] !== '' &&
            preg_match('/^[a-f0-9]{64}$/D', (string) ($order['order_token'] ?? '')) === 1) {
            $language = (string) ($order['items'][0]['language'] ?? 'cs');
            if (!in_array($language, ['cs', 'en'], true)) $language = 'cs';
            $url = $settings['public_base_url'] . '/' . $language . '/objednavka/' . $order['order_token'];
        }
        if ($url !== '' && (strlen($url) > 1000 || !filter_var($url, FILTER_VALIDATE_URL) ||
            parse_url($url, PHP_URL_SCHEME) !== 'https')) {
            throw new InvalidArgumentException('Neplatný odkaz na objednávku.');
        }
        return $url;
    }

    private static function simpleHtml(string $text): string
    {
        return '<!doctype html><html lang="cs"><meta charset="UTF-8"><body style="margin:0;padding:24px;background:#f2f5ef;font-family:Arial,sans-serif;color:#252927">' .
            '<div style="max-width:600px;margin:auto;background:#fff;border:1px solid #dde4da;border-radius:5px;overflow:hidden">' .
            '<div style="padding:20px 26px;background:#263a2b;color:#fff;font-size:22px;font-weight:bold">dobrodruzi.cz</div>' .
            '<div style="padding:26px;white-space:pre-line;line-height:1.6">' .
            htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div></body></html>';
    }
}
