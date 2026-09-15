<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Core\EntityManagerProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;

/**
 * Almacena sesiones PHP en SQL Server. La conexión se mantiene separada de
 * Doctrine para que el bloqueo de sesión no interfiera con transacciones de
 * la solicitud de negocio.
 */
final class DatabaseSessionHandler implements \SessionHandlerInterface
{
    private ?Connection $connection = null;
    private bool $sessionTransactionOpen = false;

    public function open(string $path, string $name): bool
    {
        try {
            $this->connection();
            return true;
        } catch (\Throwable $e) {
            error_log('[Session] No se pudo abrir SQL Server: ' . $e->getMessage());
            return false;
        }
    }

    public function close(): bool
    {
        if (!$this->sessionTransactionOpen) {
            return true;
        }

        try {
            $this->connection()->commit();
            $this->sessionTransactionOpen = false;
            return true;
        } catch (\Throwable $e) {
            $this->rollbackSessionTransaction();
            error_log('[Session] No se pudo cerrar la sesión SQL: ' . $e->getMessage());
            return false;
        }
    }

    public function read(string $id): string|false
    {
        try {
            $this->lock($id);
            $data = $this->connection()->fetchOne(
                'SELECT datos FROM Academico.Sesion WHERE idSesion = :id AND expiraEn > SYSUTCDATETIME()',
                ['id' => $id]
            );

            return is_string($data) ? $data : '';
        } catch (\Throwable $e) {
            $this->rollbackSessionTransaction();
            error_log('[Session] No se pudo leer la sesión SQL: ' . $e->getMessage());
            return false;
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->lock($id);
            $ttl = max(300, (int) ($_ENV['SESSION_TTL'] ?? (getenv('SESSION_TTL') ?: '3600')));
            $parameters = ['id' => $id, 'data' => $data, 'ttl' => $ttl];
            $types = ['data' => ParameterType::BINARY, 'ttl' => ParameterType::INTEGER];

            $updated = $this->connection()->executeStatement(
                'UPDATE Academico.Sesion
                 SET datos = :data, expiraEn = DATEADD(SECOND, :ttl, SYSUTCDATETIME()), actualizadaEn = SYSUTCDATETIME()
                 WHERE idSesion = :id',
                $parameters,
                $types
            );
            if ($updated === 0) {
                $this->connection()->executeStatement(
                    'INSERT INTO Academico.Sesion (idSesion, datos, expiraEn, actualizadaEn)
                     VALUES (:id, :data, DATEADD(SECOND, :ttl, SYSUTCDATETIME()), SYSUTCDATETIME())',
                    $parameters,
                    $types
                );
            }

            return true;
        } catch (\Throwable $e) {
            $this->rollbackSessionTransaction();
            error_log('[Session] No se pudo escribir la sesión SQL: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->lock($id);
            $this->connection()->executeStatement('DELETE FROM Academico.Sesion WHERE idSesion = :id', ['id' => $id]);
            return true;
        } catch (\Throwable $e) {
            $this->rollbackSessionTransaction();
            error_log('[Session] No se pudo destruir la sesión SQL: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return $this->connection()->executeStatement(
                'DELETE FROM Academico.Sesion WHERE expiraEn <= SYSUTCDATETIME()'
            );
        } catch (\Throwable $e) {
            error_log('[Session] No se pudieron limpiar sesiones SQL: ' . $e->getMessage());
            return false;
        }
    }

    private function lock(string $id): void
    {
        if ($this->sessionTransactionOpen) {
            return;
        }

        $connection = $this->connection();
        $connection->beginTransaction();
        $this->sessionTransactionOpen = true;
        $result = $connection->fetchOne(
            "DECLARE @result INT;\n"
            . "EXEC @result = sys.sp_getapplock @Resource = :resource, @LockMode = 'Exclusive', "
            . "@LockOwner = 'Transaction', @LockTimeout = 5000;\n"
            . 'SELECT @result;',
            ['resource' => 'academic-session:' . hash('sha256', $id)]
        );
        if (!is_numeric($result) || (int) $result < 0) {
            throw new \RuntimeException('No se pudo obtener el bloqueo de la sesión.');
        }
    }

    private function connection(): Connection
    {
        if ($this->connection === null) {
            $params = EntityManagerProvider::get()->getConnection()->getParams();
            $this->connection = DriverManager::getConnection($params);
        }
        return $this->connection;
    }

    private function rollbackSessionTransaction(): void
    {
        if (!$this->sessionTransactionOpen) {
            return;
        }
        try {
            $this->connection()->rollBack();
        } catch (\Throwable) {
            // La conexión ya puede haber sido cerrada por SQL Server.
        }
        $this->sessionTransactionOpen = false;
    }
}
