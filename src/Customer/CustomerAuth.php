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

    /** The caller must have resolved an identity from Google's server-side code exchange. */
    public function signInGoogle(int $id): void
    {
        $user=$this->customers->byId($id);
        if($user===null) throw new \InvalidArgumentException('Účet není dostupný.');
        $this->establishSession($user);
        $_SESSION['google_verified']=['at'=>time(),'binding'=>GoogleOAuth::binding($user)];
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
