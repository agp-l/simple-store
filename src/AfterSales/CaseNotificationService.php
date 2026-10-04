<?php
declare(strict_types=1);

namespace SimpleStore\AfterSales;

use MeekroDB;
use SimpleStore\Accounting\MailSettingsRepository;
use SimpleStore\Accounting\OrderMailQueue;
use RuntimeException;

/** The idempotent outbox stores the legal receipt even while SMTP is offline. */
final class CaseNotificationService
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function receipt(array $case): void
    {
        $this->enqueue($case, 'after-sales:' . $case['id'] . ':submitted',
            CaseNotice::receipt($case, $this->url($case)));
        try {
            $settings = (new MailSettingsRepository($this->db))->load()['settings'];
            $seller = json_decode((string) ($case['seller_json'] ?? ''), true);
            $adminEmail = (string) ($settings['admin_recovery_email'] ?: ($seller['email'] ?? ''));
            if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $body = 'Nové podání ' . $case['case_number'] . "\n" .
                    (CaseRepository::KINDS[$case['kind']] ?? $case['kind']) . "\n" .
                    'Objednávka: ' . $case['order_number'] . "\n" .
                    'Zákazník: ' . $case['customer_email'] . "\n" .
                    'Zboží: ' . $case['quantity'] . ' × ' . CaseNotice::itemLabel($case) . "\n" .
                    'Podáno: ' . CaseNotice::time((string) $case['submitted_at']) . "\n";
                $base = rtrim((string) $settings['public_base_url'], '/');
                if ($base !== '' && filter_var($base, FILTER_VALIDATE_URL) &&
                    parse_url($base, PHP_URL_SCHEME) === 'https') {
                    $body .= 'Administrace: ' . $base . '/admin.php?section=returns&id=' . (int) $case['id'] . "\n";
                }
                $this->enqueue($case, 'after-sales:' . $case['id'] . ':admin',
                    ['subject' => 'Nové podání ' . $case['case_number'], 'text' => $body,
                        'html' => '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>'],
                    $adminEmail);
            }
        } catch (\Throwable $error) {
            error_log('Case administrator notification: ' . $error->getMessage());
        }
    }

    public function event(array $case, int $eventId, string $message): void
    {
        $this->enqueue($case, 'after-sales:' . $case['id'] . ':event:' . $eventId,
            CaseNotice::event($case, $message, $this->url($case)));
    }

    public function state(array $case): string
    {
        if (!(new OrderMailQueue($this->db))->installed()) return 'missing';
        $row = $this->db->queryFirstRow(
            'SELECT state FROM shop_mail_outbox WHERE event_key=%s LIMIT 1',
            'after-sales:' . (int) $case['id'] . ':submitted'
        );
        return (string) ($row['state'] ?? 'missing');
    }

    private function enqueue(array $case, string $key, array $mail, ?string $recipient = null): void
    {
        $queue = new OrderMailQueue($this->db);
        if (!$queue->installed()) throw new RuntimeException('E-mailová fronta není připravená. Aktualizuj SQL tabulky.');
        // Orders can be removed independently; never let order cleanup discard
        // the separate complaint/withdrawal receipt or its pending notification.
        $id = $queue->enqueueCustom($key, null,
            $recipient ?? (string) $case['customer_email'], $mail['subject'], $mail['text'], $mail['html']);
        // Statutory receipt/outcome mail is independent of optional order-stage emails.
        if (filter_var($queue->sender(), FILTER_VALIDATE_EMAIL)) {
            $queue->dispatch($id, '', true);
        }
    }

    private function url(array $case): string
    {
        $settings = (new MailSettingsRepository($this->db))->load()['settings'];
        return CaseNotice::publicUrl($case, (string) $settings['public_base_url']);
    }
}
