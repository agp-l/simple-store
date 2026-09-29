<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use SimpleStore\Auth\RoleAuth;

/** An administrator session can never authenticate a customer account. */
final class AdminAuth extends RoleAuth
{
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
}
