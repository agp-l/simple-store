<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use RuntimeException;
use SimpleStore\Auth\RoleAuth;

/** An administrator session can never authenticate a customer account. */
final class AdminAuth extends RoleAuth
{
    private const VISITOR_PREVIEW_KEY = 'admin_visitor_preview';

    public function __construct(private AdminUserRepository $users, string $cookiePath)
    {
        parent::__construct($cookiePath, 'simple_store_admin', 'admin', 'Strict');
    }

    protected function byName(string $name): ?array
    {
        return $this->users->findAdminByUsername($name);
    }

    protected function byId(int $id): ?array
    {
        return $this->users->findAdminById($id);
    }

    public function signIn(string $name, string $password): bool
    {
        if (!parent::signIn($name, $password)) return false;
        unset($_SESSION[self::VISITOR_PREVIEW_KEY]);
        return true;
    }

    public function visitorPreviewEnabled(): bool
    {
        return $this->signedIn() && ($_SESSION[self::VISITOR_PREVIEW_KEY] ?? false) === true;
    }

    public function setVisitorPreview(bool $enabled): void
    {
        if (!$this->signedIn()) throw new RuntimeException('Administrator login required.');
        if ($enabled) {
            $_SESSION[self::VISITOR_PREVIEW_KEY] = true;
        } else {
            unset($_SESSION[self::VISITOR_PREVIEW_KEY]);
        }
    }
}
