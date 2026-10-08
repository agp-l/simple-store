<?php
declare(strict_types=1);

namespace {
    class MeekroDB
    {
        public int $insertions = 0;
        public int $deletions = 0;
        public bool $throttled = false;

        public function queryFirstField(string $sql, mixed ...$values): int
        {
            return str_contains($sql, 'information_schema.TABLES') ||
                ($this->throttled && str_contains($sql, 'FROM shop_password_resets')) ? 1 : 0;
        }

        public function queryFirstRow(string $sql, mixed ...$values): ?array
        {
            return ['id' => 1, 'password_hash' => password_hash('existing-password', PASSWORD_DEFAULT)];
        }

        public function query(string $sql, mixed ...$values): void
        {
            if (str_starts_with($sql, 'INSERT INTO shop_password_resets')) $this->insertions++;
            if (str_starts_with($sql, 'DELETE FROM shop_password_resets')) $this->deletions++;
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
    require dirname(__DIR__) . '/src/Admin/AdminUserRepository.php';
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
    $service->request('admin', '', 'admin.php', 'https://other.example.test/shop/admin.php');
    if ($db->insertions !== 2 || count($messages) !== 2) {
        throw new \RuntimeException('Administrator recovery without another email input failed.');
    }
    $db->throttled = true;
    try {
        $service->request('admin', '', 'admin.php', 'https://other.example.test/shop/admin.php');
        throw new \RuntimeException('The administrator was not told to wait before resending.');
    } catch (\RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'pět minut')) throw $error;
    }
    $db->throttled = false;
    $failed = new \SimpleStore\Auth\PasswordResetService($db, static fn (): bool => false);
    try {
        $failed->request('admin', '', 'admin.php', 'https://other.example.test/shop/admin.php');
        throw new \RuntimeException('Mail failure was hidden from the administrator.');
    } catch (\RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'nepodařilo odeslat') || $db->deletions !== 1) throw $error;
    }
    echo "Password reset URL validation OK\n";
}
