<?php
declare(strict_types=1);

namespace SimpleStore\Database;

use InvalidArgumentException;
use MeekroDB;

/** Build one MeekroDB connection from the local configuration. */
final class ConnectionFactory
{
    public static function create(array $config): MeekroDB
    {
        // Older installations stored a PDO DSN; read it without making users replace their credentials.
        if (isset($config['dsn'])) {
            $dsn = (string) $config['dsn'];
            if (!str_starts_with($dsn, 'mysql:')) {
                throw new InvalidArgumentException('Only MySQL connections are supported.');
            }

            $options = [];
            foreach (explode(';', substr($dsn, strlen('mysql:'))) as $option) {
                if ($option === '') {
                    continue;
                }
                [$name, $value] = array_pad(explode('=', $option, 2), 2, '');
                $options[$name] = $value;
            }
            $config['host'] = $options['host'] ?? 'localhost';
            $config['database'] = $options['dbname'] ?? '';
            $config['port'] = $options['port'] ?? 3306;
            $config['charset'] = $options['charset'] ?? 'utf8mb4';
            $config['socket'] = $options['unix_socket'] ?? null;
        }

        $database = (string) ($config['database'] ?? '');
        $port = (int) ($config['port'] ?? 3306);
        if ($database === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Set a database name and valid port in config/database.php.');
        }

        // MeekroDB 2.5.2 uses mysqli and separate host/database arguments, not a PDO DSN.
        return new MeekroDB(
            (string) ($config['host'] ?? '127.0.0.1'),
            (string) ($config['user'] ?? ''),
            (string) ($config['password'] ?? ''),
            $database,
            $port,
            (string) ($config['charset'] ?? 'utf8mb4'),
            $config['socket'] ?? null
        );
    }
}
