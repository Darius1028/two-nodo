<?php
declare(strict_types=1);

namespace App\Infrastructure;

use App\Exception\InvalidConfigurationException;

final class SessionStoreConfigurator
{
    private static bool $configured = false;
    private static ?DatabaseSessionHandler $databaseHandler = null;

    private function __construct()
    {
    }

    public static function configure(): void
    {
        if (self::$configured || session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $handler = strtolower(trim((string) ($_ENV['SESSION_HANDLER'] ?? (getenv('SESSION_HANDLER') ?: 'files'))));
        if ($handler === 'files') {
            self::$configured = true;
            return;
        }
        if ($handler === 'database') {
            self::$databaseHandler ??= new DatabaseSessionHandler();
            if (!session_set_save_handler(self::$databaseHandler, true)) {
                throw new InvalidConfigurationException('PHP no permitió configurar el manejador de sesiones SQL Server.');
            }
            $ttl = $_ENV['SESSION_TTL'] ?? (getenv('SESSION_TTL') ?: '3600');
            ini_set('session.gc_maxlifetime', (string) max(300, (int) $ttl));
            $sessionName = $_ENV['SESSION_NAME'] ?? (getenv('SESSION_NAME') ?: 'ACADEMICSESSID');
            session_name((string) $sessionName);
            self::$configured = true;
            return;
        }
        throw new InvalidConfigurationException('SESSION_HANDLER debe ser "files" o "database".');
    }
}
