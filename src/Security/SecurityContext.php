<?php
declare(strict_types=1);

namespace App\Security;

use Jumbojett\OpenIDConnectClientException;

class SecurityContext
{
    private const SESSION_USER   = 'keycloak_user';
    private const SESSION_TOKENS = 'keycloak_tokens';

    public static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function getCurrentUser(): ?array
    {
        self::ensureSession();
        if (!isset($_SESSION[self::SESSION_USER])) {
            return null;
        }
        $tokens = $_SESSION[self::SESSION_TOKENS] ?? null;
        if ($tokens === null || ($tokens['expires_at'] ?? 0) < time()) {
            if (!self::refreshTokens()) {
                self::clearSession();
                return null;
            }
        }
        return $_SESSION[self::SESSION_USER];
    }

    public static function requireAuthentication(): array
    {
        $user = self::getCurrentUser();
        if ($user !== null) {
            return $user;
        }
        self::redirectToKeycloak();
        exit;
    }

    public static function requireRole(string $role): void
    {
        $user = self::requireAuthentication();
        if (!self::hasRole($role)) {
            http_response_code(403);
            echo '<h1>Acceso Denegado</h1>';
            echo '<p>El usuario <strong>' . htmlspecialchars($user['preferred_username'] ?? 'unknown') . '</strong> no tiene el rol <code>' . htmlspecialchars($role) . '</code>.</p>';
            echo '<p><a href="logout.php">Cerrar sesión</a></p>';
            exit;
        }
    }

    public static function hasRole(string $role): bool
    {
        $user = self::getCurrentUser();
        if ($user === null) {
            return false;
        }
        // Autorización desacoplada de Keycloak: los roles NO vienen del
        // token (Keycloak/LDAP solo autentica), sino de la base de datos
        // externa, resuelta por cédula. Ver RoleProvider::getRolesForCedula().
        return in_array($role, $user['external_roles'] ?? [], true);
    }

    public static function redirectToKeycloak(): void
    {
        self::ensureSession();
        $_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? 'workspace.php';
        $oidc = KeycloakClient::get();
        $oidc->setRedirectURL($_ENV['KEYCLOAK_REDIRECT_URI'] ?? '');
        $oidc->authenticate();
    }

    public static function handleCallback(): void
    {
        self::ensureSession();
        try {
            $oidc = KeycloakClient::get();
            $oidc->setRedirectURL($_ENV['KEYCLOAK_REDIRECT_URI'] ?? '');
            $oidc->authenticate();

            $accessToken  = $oidc->getAccessToken();
            $idToken      = $oidc->getIdTokenPayload();
            $refreshToken = $oidc->getRefreshToken();

            $decodedAccess = self::decodeJwtPayload((string)$accessToken);

            // El nombre exacto del claim con la cédula depende de cómo el
            // administrador de Keycloak mapeó el atributo de LDAP/AD hacia
            // el perfil del usuario (ej. "employeeID" -> claim "cedula").
            // Configurable por si no es "cedula" literal en el token.
            $cedulaClaim = $_ENV['KEYCLOAK_CEDULA_CLAIM'] ?? 'cedula';
            $cedula = (string)($idToken->{$cedulaClaim} ?? $idToken->preferred_username ?? '');

            $user = [
                'sub'                => $idToken->sub ?? '',
                'cedula'             => $cedula,
                'preferred_username' => $idToken->preferred_username ?? 'unknown',
                'email'              => $idToken->email ?? '',
                'given_name'         => $idToken->given_name ?? '',
                'family_name'        => $idToken->family_name ?? '',
                'name'               => $idToken->name ?? '',
                // Se conservan por si en algún momento se quieren usar
                // como fallback o para debug, pero hasRole() ya NO los usa.
                'client_roles'       => self::extractClientRoles($decodedAccess),
                'realm_roles'        => self::extractRealmRoles($decodedAccess),
                // Autorización real: roles resueltos por cédula en la base
                // externa (SQL Server distinto al de academic_records).
                'external_roles'     => RoleProvider::getRolesForCedula($cedula),
            ];

            $_SESSION[self::SESSION_USER] = $user;
            $_SESSION[self::SESSION_TOKENS] = [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'id_token'      => $oidc->getIdToken(),
                'expires_at'    => time() + (($idToken->exp ?? time()) - time()),
            ];

            $returnTo = $_SESSION['return_to'] ?? 'workspace.php';
            unset($_SESSION['return_to']);
            header('Location: ' . $returnTo);
            exit;
        } catch (OpenIDConnectClientException $e) {
            error_log('Keycloak callback error: ' . $e->getMessage());
            http_response_code(401);
            exit('Error de autenticación: ' . $e->getMessage());
        }
    }

    public static function logout(): void
    {
        self::ensureSession();
        $tokens = $_SESSION[self::SESSION_TOKENS] ?? null;
        self::clearSession();

        if ($tokens !== null && !empty($tokens['id_token'])) {
            try {
                $oidc = KeycloakClient::get();
                $oidc->signOut($tokens['id_token'], $_ENV['KEYCLOAK_REDIRECT_URI'] ?? '');
                exit;
            } catch (\Throwable $e) {
                error_log('Keycloak SLO error: ' . $e->getMessage());
            }
        }
        header('Location: workspace.php');
        exit;
    }

    private static function refreshTokens(): bool
    {
        $tokens = $_SESSION[self::SESSION_TOKENS] ?? null;
        if ($tokens === null || empty($tokens['refresh_token'])) {
            return false;
        }
        try {
            $oidc = KeycloakClient::get();
            $oidc->refreshToken($tokens['refresh_token']);

            $newAccessToken  = $oidc->getAccessToken();
            $newRefreshToken = $oidc->getRefreshToken() ?? $tokens['refresh_token'];

            // Al refrescar el token, los roles también pueden haber cambiado
            // en la base externa (ej. a alguien le sacan/agregan un rol).
            $decoded = self::decodeJwtPayload((string)$newAccessToken);
            if (isset($_SESSION[self::SESSION_USER])) {
                $_SESSION[self::SESSION_USER]['client_roles'] = self::extractClientRoles($decoded);
                $_SESSION[self::SESSION_USER]['realm_roles']  = self::extractRealmRoles($decoded);
                $cedula = $_SESSION[self::SESSION_USER]['cedula'] ?? '';
                $_SESSION[self::SESSION_USER]['external_roles'] = RoleProvider::getRolesForCedula($cedula);
            }

            $_SESSION[self::SESSION_TOKENS] = [
                'access_token'  => $newAccessToken,
                'refresh_token' => $newRefreshToken,
                'id_token'      => $oidc->getIdToken(),
                'expires_at'    => time() + 300,
            ];
            return true;
        } catch (\Throwable $e) {
            error_log('Token refresh failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        return is_array($payload) ? $payload : [];
    }

    private static function extractClientRoles(array $payload): array
    {
        $clientId = $_ENV['KEYCLOAK_CLIENT_ID'] ?? '';
        return $payload['resource_access'][$clientId]['roles'] ?? [];
    }

    private static function extractRealmRoles(array $payload): array
    {
        return $payload['realm_access']['roles'] ?? [];
    }

    private static function clearSession(): void
    {
        unset($_SESSION[self::SESSION_USER], $_SESSION[self::SESSION_TOKENS]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
