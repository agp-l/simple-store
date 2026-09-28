<?php
declare(strict_types=1);

use SimpleStore\Database\ConnectionFactory;

// A local stand-in tests the constructor arguments without opening a database connection.
class MeekroDB
{
    public function __construct(public string $host, public string $user, public string $password,
        public string $database, public int $port, public string $charset, public ?string $socket)
    {
    }
}

require dirname(__DIR__) . '/src/Database/ConnectionFactory.php';

$old = ConnectionFactory::create([
    'dsn' => 'mysql:host=127.0.0.1;dbname=simple_store;charset=utf8mb4',
    'user' => 'root', 'password' => '',
]);
$new = ConnectionFactory::create([
    'host' => 'localhost', 'database' => 'simple_store', 'port' => 3307,
    'charset' => 'utf8mb4', 'user' => 'root', 'password' => '',
]);
if ($old->host !== '127.0.0.1' || $old->database !== 'simple_store' ||
    $old->charset !== 'utf8mb4' || $new->host !== 'localhost' || $new->port !== 3307) {
    throw new RuntimeException('Database configuration was passed to MeekroDB incorrectly.');
}

echo "Database connection configuration tests passed.\n";
