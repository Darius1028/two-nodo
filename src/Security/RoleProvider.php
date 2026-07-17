<?php
declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Resuelve los roles de un usuario contra una base de datos externa
 * (SQL Server distinto al de academic_records), cruzando por cédula.
 *
 * Usa una conexión DBAL propia y liviana -- no un EntityManager -- porque
 * no necesitamos mapear entidades de una base que no es "nuestra" ni
 * administramos su esquema, solo leer una tabla de roles.
 *
 * IMPORTANTE: ajustar EXTERNAL_ROLES_TABLE / EXTERNAL_ROLES_CEDULA_COLUMN /
 * EXTERNAL_ROLES_ROLE_COLUMN en el .env para que coincidan con el nombre
 * real de la tabla y columnas en esa base externa.
 */
class RoleProvider
{
    private static ?Connection $connection = null;

    private static function getConnection(): Connection
    {
        if (self::$connection === null) {
            $serverName = !empty($_ENV['EXTERNAL_ROLES_DB_INSTANCE'])
                ? "{$_ENV['EXTERNAL_ROLES_DB_HOST']}\\{$_ENV['EXTERNAL_ROLES_DB_INSTANCE']},{$_ENV['EXTERNAL_ROLES_DB_PORT']}"
                : "{$_ENV['EXTERNAL_ROLES_DB_HOST']},{$_ENV['EXTERNAL_ROLES_DB_PORT']}";

            self::$connection = DriverManager::getConnection([
                'driver'        => 'pdo_sqlsrv',
                'host'          => $serverName,
                'dbname'        => $_ENV['EXTERNAL_ROLES_DB_NAME'] ?? 'PORTAL_APLICATIVOS_CJ',
                'user'          => $_ENV['EXTERNAL_ROLES_DB_USER'] ?? 'sa',
                'password'      => $_ENV['EXTERNAL_ROLES_DB_PASS'] ?? '',
                'charset'       => 'UTF-8',
                'driverOptions' => [
                    'Encrypt'                => 'no',   // <- string 'no', no false
                    'TrustServerCertificate' => 'yes',  // <- string 'yes', no true
                    'LoginTimeout'           => 15,
                ],
            ]);
        }
        return self::$connection;
    }

    /**
     * @return string[] Lista de roles del usuario según la BD externa
     *                   (ej. ['ROLE_ADMIN', 'ROLE_USER']). Vacío si no
     *                   tiene roles asignados o si la consulta falla.
     */
    public static function getRolesForCedula(string $cedula): array
    {
        if ($cedula === '') {
            return [];
        }

        // Nombres configurables porque no controlamos el esquema de esta
        // base externa -- ajustar en .env según cómo esté modelada
        // realmente la tabla de roles.
        $table       = $_ENV['EXTERNAL_ROLES_TABLE']         ?? 'usuario_roles';
        $cedulaCol   = $_ENV['EXTERNAL_ROLES_CEDULA_COLUMN'] ?? 'cedula';
        $roleCol     = $_ENV['EXTERNAL_ROLES_ROLE_COLUMN']   ?? 'rol';

        try {
            $conn = self::getConnection();
            $sql = sprintf(
                'SELECT DISTINCT %s AS rol FROM %s WHERE %s = :cedula',
                $roleCol,
                $table,
                $cedulaCol
            );
            $rows = $conn->executeQuery($sql, ['cedula' => $cedula])->fetchFirstColumn();
            return array_values(array_filter(array_map(
                static fn($r) => trim((string)$r),
                $rows
            )));
        } catch (\Throwable $e) {
            error_log('RoleProvider: error consultando roles externos para cédula ' . $cedula . ': ' . $e->getMessage());
            return [];
        }
    }
}
