<?php
declare(strict_types=1);

namespace {
    class MeekroDB
    {
        public int $insertions = 0;

        public function queryFirstField(string $sql, mixed ...$values): int
        {
            return str_contains($sql, 'information_schema.TABLES') ? 1 : 0;
        }

        public function queryFirstRow(string $sql, mixed ...$values): ?array
        {
            return ['id' => 1, 'password_hash' => password_hash('existing-password', PASSWORD_DEFAULT)];
        }

        public function query(string $sql, mixed ...$values): void
        {
            if (str_starts_with($sql, 'INSERT INTO shop_password_resets')) $this->insertions++;
        }
    }
}

namespace SimpleStore\Accounting {
    class MailSettingsRepository
    {
        public function __construct(\MeekroDB $db) {}

        public function load(): array
        {
            return ['settings' => ['from_email' => 'shop@example.test',
                'from_name' => 'Example', 'public_base_url' => 'https://other.example.test/shop',
                'admin_recovery_email' => 'owner@example.test']];
        }
    }
}

namespace {
    require dirname(__DIR__) . '/src/Auth/PasswordResetService.php';

    $db = new MeekroDB();
    $messages = [];
    $service = new \SimpleStore\Auth\PasswordResetService($db,
        static function (string $email, string $subject, string $message) use (&$messages): bool {
            $messages[] = $message;
            return true;
        });

    foreach (['https://shop.example.test/shop/admin.php', 'https://other.example.test/admin.php'] as $wrong) {
        try {
            $service->request('admin', 'owner@example.test', 'admin.php', $wrong);
            throw new \RuntimeException('A password reset was sent to another installation.');
        } catch (\RuntimeException $error) {
            if (!str_contains($error->getMessage(), 'Veřejná HTTPS adresa')) throw $error;
        }
    }
    if ($db->insertions !== 0 || $messages !== []) {
        throw new \RuntimeException('A mismatched URL created a recovery token.');
    }

    $service->request('admin', 'owner@example.test', 'admin.php',
        'https://other.example.test/shop/admin.php');
    if ($db->insertions !== 1 || count($messages) !== 1 ||
        !str_contains($messages[0], 'https://other.example.test/shop/admin.php?mode=reset&token=')) {
        throw new \RuntimeException('A matching installation did not send a recovery link.');
    }
    echo "Password reset URL validation OK\n";
}
