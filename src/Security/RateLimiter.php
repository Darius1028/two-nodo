<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\EntityManagerProvider;
use App\Exception\InvalidConfigurationException;

/**
 * Rate limiting para endpoints públicos sin sesión. En despliegues con más
 * de varios nodos usa SQL Server; el archivo con locking queda
 * como fallback explícito para desarrollo de un solo nodo.
 */
final class RateLimiter
{
    private const PATH = __DIR__ . '/../../var/cache/rate_limit.json';

    /**
     * @return bool true si la request entra dentro del límite, false si hay
     *              que rechazarla (demasiadas requests para esta $key en
     *              la ventana de tiempo).
     */
    public static function allow(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $store = strtolower(trim((string) ($_ENV['RATE_LIMIT_STORE'] ?? (getenv('RATE_LIMIT_STORE') ?: 'file'))));
        if ($store === 'database') {
            return self::allowDatabase($key, $maxRequests, $windowSeconds);
        }
        if ($store !== 'file') {
            throw new InvalidConfigurationException('RATE_LIMIT_STORE debe ser "file" o "database".');
        }

        $dir = dirname(self::PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fp = fopen(self::PATH, 'c+');
        if ($fp === false) {
            // Si no se puede abrir el archivo (permisos, disco lleno, etc.)
            // no bloqueamos el servicio real por un problema del limiter.
            return true;
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $now = time();
        // Limpieza de entradas vencidas para que el archivo no crezca sin
        // límite con IPs viejas.
        foreach ($data as $k => $entry) {
            if (($entry['reset_at'] ?? 0) < $now) {
                unset($data[$k]);
            }
        }

        $entry = $data[$key] ?? null;
        if ($entry === null || ($entry['reset_at'] ?? 0) < $now) {
            $entry = ['count' => 0, 'reset_at' => $now + $windowSeconds];
        }

        $entry['count']++;
        $data[$key] = $entry;
        $allowed = $entry['count'] <= $maxRequests;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $allowed;
    }

    private static function allowDatabase(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $connection = null;
        try {
            $connection = EntityManagerProvider::get()->getConnection();
            $counterKey = hash('sha256', $key);
            $connection->beginTransaction();
            $lock = $connection->fetchOne(
                "DECLARE @result INT;\n"
                . "EXEC @result = sys.sp_getapplock @Resource = :resource, @LockMode = 'Exclusive', "
                . "@LockOwner = 'Transaction', @LockTimeout = 5000;\n"
                . 'SELECT @result;',
                ['resource' => 'academic-rate-limit:' . $counterKey]
            );
            if (!is_numeric($lock) || (int) $lock < 0) {
                throw new \RuntimeException('No se pudo bloquear el contador de rate limit.');
            }

            $row = $connection->fetchAssociative(
                'SELECT contador, expiraEn FROM Academico.RateLimit WHERE clave = :key',
                ['key' => $counterKey]
            );
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $expires = $row === false
                ? null
                : ($row['expiraEn'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($row['expiraEn'])
                    : new \DateTimeImmutable((string) $row['expiraEn'], new \DateTimeZone('UTC')));
            if ($row === false) {
                $count = 1;
                $connection->executeStatement(
                    'INSERT INTO Academico.RateLimit (clave, contador, expiraEn, actualizadaEn)
                     VALUES (:key, 1, DATEADD(SECOND, CAST(:window AS INT), SYSUTCDATETIME()), SYSUTCDATETIME())',
                    ['key' => $counterKey, 'window' => max(1, $windowSeconds)]
                );
            } elseif ($expires <= $now) {
                $count = 1;
                $connection->executeStatement(
                    'UPDATE Academico.RateLimit
                     SET contador = 1, expiraEn = DATEADD(SECOND, CAST(:window AS INT), SYSUTCDATETIME()), actualizadaEn = SYSUTCDATETIME()
                     WHERE clave = :key',
                    ['key' => $counterKey, 'window' => max(1, $windowSeconds)]
                );
            } else {
                $count = (int) $row['contador'] + 1;
                $connection->executeStatement(
                    'UPDATE Academico.RateLimit SET contador = :count, actualizadaEn = SYSUTCDATETIME() WHERE clave = :key',
                    ['key' => $counterKey, 'count' => $count]
                );
            }
            $connection->commit();
            return $count <= max(1, $maxRequests);
        } catch (\Throwable $e) {
            if ($connection !== null && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            error_log('[RateLimiter] SQL Server no disponible: ' . $e->getMessage());
            return self::failOpen();
        }
    }

    private static function failOpen(): bool
    {
        $environment = strtolower((string) ($_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: 'prod')));
        $defaultFailOpen = in_array($environment, ['dev', 'development', 'local', 'test'], true);
        return filter_var(
            $_ENV['RATE_LIMIT_FAIL_OPEN']
                ?? (getenv('RATE_LIMIT_FAIL_OPEN') ?: ($defaultFailOpen ? 'true' : 'false')),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
