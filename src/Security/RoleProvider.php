<?php
declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Resuelve los roles de un usuario contra la base institucional
 * PORTAL_APLICATIVOS_CJ (esquema ADM), cruzando por cédula O por username
 * (lo que primero coincida) -- porque Keycloak/LDAP puede entregar la
 * cédula con un formato distinto al de la base (con guion vs. sin guion),
 * o en algunos casos puede no traer el claim de cédula bien mapeado y solo
 * tener disponible el username de AD.
 *
 * Esquema real (compartido entre varias apps institucionales):
 *   Persona.identificacion       -> cédula
 *   Usuario.idPersona            -> FK a Persona
 *   Usuario.username/usernameSatje -> usuario de red / de otro sistema
 *   UsuarioRol.idUsuario         -> FK a Usuario
 *   UsuarioRol.idRol             -> FK a Rol
 *   Rol.idAplicativo             -> FK a Aplicativo (filtra los roles de
 *                                   ESTA app dentro de la tabla compartida)
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
                    'Encrypt'                => 'no',
                    'TrustServerCertificate' => 'yes',
                    'LoginTimeout'           => 15,
                ],
            ]);
        }
        return self::$connection;
    }

    private static function normalizeCedula(string $cedula): string
    {
        $digits = preg_replace('/\D+/', '', $cedula);
        return $digits !== '' ? $digits : $cedula;
    }

    /**
     * @return string[] Lista de nombres de rol (ADM.Rol.nombre) que el
     *                   usuario tiene asignados para esta aplicación
     *                   (EXTERNAL_ROLES_APP_ALIAS), buscando por cédula O
     *                   por username -- lo que primero coincida.
     */
    public static function getRolesForUser(string $cedula, string $username = ''): array
    {
        $roles = []; // Inicializamos el valor por defecto
        $cedula = self::normalizeCedula($cedula);

        if ($cedula === '' && $username === '') {
            return $roles; // Retorno 1
        }

        $appAlias = $_ENV['EXTERNAL_ROLES_APP_ALIAS'] ?? '';
        if ($appAlias === '') {
            error_log('RoleProvider: falta EXTERNAL_ROLES_APP_ALIAS en el .env.');
            return $roles; // Retorno 2
        }

        try {
            $conn = self::getConnection();
            $sql = <<<SQL
                SELECT DISTINCT r.nombre AS rol
                FROM ADM.Usuario u
                LEFT JOIN ADM.Persona p ON p.id = u.idPersona
                JOIN ADM.UsuarioRol ur ON ur.idUsuario = u.id
                JOIN ADM.Rol r        ON r.id = ur.idRol
                JOIN ADM.Aplicativo a ON a.id = r.idAplicativo
                WHERE (
                    (:cedula   <> '' AND p.identificacion = :cedula)
                    OR (:username <> '' AND u.username      = :username)
                    OR (:username <> '' AND u.usernameSatje  = :username)
                )
                AND a.alias = :appAlias
                AND ur.estado = 'ACT'
                AND (ur.fechaFin IS NULL OR ur.fechaFin > GETDATE())
                SQL;

            $rows = $conn->executeQuery($sql, [
                'cedula'   => $cedula,
                'username' => $username,
                'appAlias' => $appAlias,
            ])->fetchFirstColumn();

            // Asignamos a la variable en lugar de hacer un return inmediato
            $roles = array_values(array_filter(array_map(
                static fn($r) => trim((string)$r),
                $rows
            )));
        } catch (\Throwable $e) {
            error_log('RoleProvider: error consultando roles para cédula=' . $cedula . ' username=' . $username . ': ' . $e->getMessage());
            // No hacemos return aquí; el flujo continuará hacia el final
        }

        return $roles; // Retorno 3 (Cubre el caso de éxito y el catch)
    }

    /**
     * Resuelve el id numérico de ADM.Usuario (para idPersonaCrea /
     * idPersonaModifica en la auditoría institucional), buscando por
     * cédula O username -- mismo criterio que getRolesForUser().
     */
    public static function getUsuarioId(string $cedula, string $username = ''): ?int
    {
        $cedula = self::normalizeCedula($cedula);
        if ($cedula === '' && $username === '') {
            return null;
        }

        try {
            $conn = self::getConnection();
            $sql = <<<SQL
                SELECT TOP 1 u.id
                FROM ADM.Usuario u
                LEFT JOIN ADM.Persona p ON p.id = u.idPersona
                WHERE (:cedula   <> '' AND p.identificacion = :cedula)
                   OR (:username <> '' AND u.username        = :username)
                   OR (:username <> '' AND u.usernameSatje    = :username)
                SQL;

            $id = $conn->executeQuery($sql, [
                'cedula'   => $cedula,
                'username' => $username,
            ])->fetchOne();

            return $id !== false ? (int)$id : null;
        } catch (\Throwable $e) {
            error_log('RoleProvider: error resolviendo idUsuario para cédula=' . $cedula . ' username=' . $username . ': ' . $e->getMessage());
            return null;
        }
    }

    /** @deprecated usar getRolesForUser() -- se deja por compatibilidad. */
    public static function getRolesForCedula(string $cedula): array
    {
        return self::getRolesForUser($cedula, '');
    }
}
