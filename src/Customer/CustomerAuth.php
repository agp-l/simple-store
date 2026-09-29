<?php
declare(strict_types=1);

namespace SimpleStore\Customer;

use SimpleStore\Auth\RoleAuth;

/** Customer cookies and role checks are separate from administrator access. */
final class CustomerAuth extends RoleAuth
{
    public function __construct(private CustomerRepository $customers, string $cookiePath)
    {
        parent::__construct($cookiePath, 'simple_store_customer', 'customer');
    }

    protected function byName(string $name): ?array
    {
        return $this->customers->byEmail($name);
    }

    protected function byId(int $id): ?array
    {
        return $this->customers->byId($id);
    }
}
