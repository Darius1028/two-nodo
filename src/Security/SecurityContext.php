<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\KeycloakClient;
use App\Security\RoleProvider;
use App\Security\AuthResponder;

/**
 * Gestiona la sesión del usuario autenticado vía Keycloak, la resolución
 * de roles desde la base institucional externa y las redirecciones de
 * login/logout/callback.
 */
class SecurityContext
{
    private const SESSION_USER   = 'keycloak_user';
    private const SESSION_TOKENS = 'keycloak_tokens';

    // ────────────────────────────────────────────────────────────────────
    // Sesión y usuario actual
    // ────────────────────────────────────────────────────────────────────

    public static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_secure'   => ($_ENV['APP_ENV'] ?? 'prod') === 'prod',
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }

        if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
        }
    }

    public static function getCurrentUser(): ?array
    {
        self::ensureSession();

        if (!isset($_SESSION[self::SESSION_USER])) {
            return null;
        }

        $tokens = $_SESSION[self::SESSION_TOKENS] ?? null;

        if (($tokens === null || ($tokens['expires_at'] ?? 0) < time())
                && !self::refreshTokens()
        ) {
            self::clearSession();
            return null;
        }

        return $_SESSION[self::SESSION_USER];
    }

    public static function getAccessToken(): ?string
    {
        if (self::getCurrentUser() === null) {
            return null;
        }

        $accessToken = $_SESSION[self::SESSION_TOKENS]['access_token'] ?? null;

        if (!is_string($accessToken) || trim($accessToken) === '') {
            return null;
        }

        return trim($accessToken);
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

    public static function getCurrentUserId(): ?int
    {
        $user = self::getCurrentUser();

        if ($user === null) {
            return null;
        }

        if (!array_key_exists('idUsuario', $user)) {
            error_log('[SecurityContext] idUsuario no está en sesión — resolviendo lazy'
                    . ' | cedula=' . ($user['cedula'] ?? '')
                    . ' | username=' . ($user['preferred_username'] ?? ''));

            self::ensureSession();

            $id = RoleProvider::getUsuarioId(
                    $user['cedula'] ?? '',
                    $user['preferred_username'] ?? ''
            );

            error_log('[SecurityContext] idUsuario resuelto lazy: '
                    . ($id === null ? 'null' : (string) $id));

            $_SESSION[self::SESSION_USER]['idUsuario'] = $id;

            return $id;
        }

        return $user['idUsuario'];
    }

    // ───────────────────────────────────────────────────────────────────
    // Autorización por rol
    // ───────────────────────────────────────────────────────────────────

    public static function requireRole(string $role): void
    {
        self::ensureSession();
        $user = self::getCurrentUser();

        if ($user === null) {
            self::redirectToKeycloak();
            exit;
        }

        if (!self::hasRole($role)) {
            AuthResponder::renderAccessDeniedPage();
            exit;
        }
    }

    public static function hasRole(string $role): bool
    {
        $user = self::getCurrentUser();

        if ($user === null) {
            return false;
        }

        return in_array($role, $user['external_roles'] ?? [], true);
    }

    // ────────────────────────────────────────────────────────────────────
    // Login / Logout / Callback
    // ────────────────────────────────────────────────────────────────────

    public static function redirectToKeycloak(): void
    {
        self::ensureSession();

        $requestUri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $requestPath = parse_url($requestUri, PHP_URL_PATH) ?? '';

        $invalidReturnTargets = ['login.php', 'callback.php'];
        $isInvalidReturnTarget = in_array(
                basename($requestPath),
                $invalidReturnTargets,
                true
        );

        $_SESSION['return_to'] = ($requestUri !== '' && !$isInvalidReturnTarget)
                ? $requestUri
                : self::defaultDestinationForCurrentRole();

        try {
            $oidc = KeycloakClient::get();
            $oidc->setRedirectURL($_ENV['KEYCLOAK_REDIRECT_URI'] ?? '');
            $oidc->authenticate();
        } catch (\Throwable $e) {
            error_log('Keycloak redirect error: ' . $e->getMessage());
            AuthResponder::renderLoginFailedPage();
        }
    }

    public static function handleCallback(): void
    {
        self::ensureSession();

        $incomingCode = is_string($_GET['code'] ?? null) ? $_GET['code'] : null;

        if (self::handleAlreadyProcessedCode($incomingCode)) {
            exit;
        }

        try {
            $oidc = KeycloakClient::get();
            $oidc->setRedirectURL($_ENV['KEYCLOAK_REDIRECT_URI'] ?? '');
            $oidc->authenticate();

            $accessToken  = $oidc->getAccessToken();
            $idToken      = $oidc->getIdTokenPayload();
            $refreshToken = $oidc->getRefreshToken();
            $decodedAccess = self::decodeJwtPayload((string) $accessToken);

            $user = self::buildUserFromTokens($idToken, $decodedAccess);

            $_SESSION[self::SESSION_USER] = $user;
            $_SESSION[self::SESSION_TOKENS] = [
                    'access_token'  => $accessToken,
                    'refresh_token' => $refreshToken,
                    'id_token'      => $oidc->getIdToken(),
                    'expires_at'    => (int) ($decodedAccess['exp'] ?? ($idToken->exp ?? time())),
            ];

            $adminRole = $_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ADMIN_ACADEMICO';
            $userRole  = $_ENV['KEYCLOAK_ROLE_USER']  ?? 'SECRE_ACADEMICO';

            $hasAdmin = in_array($adminRole, $user['external_roles'] ?? [], true);
            $hasUser  = in_array($userRole,  $user['external_roles'] ?? [], true);

            if (!$hasAdmin && !$hasUser) {
                self::clearSession();
                AuthResponder::renderNoRolesPage(
                        $user['preferred_username'] ?? 'unknown',
                        $adminRole,
                        $userRole
                );
                return;
            }

            $returnTo = $_SESSION['return_to'] ?? '';
            unset($_SESSION['return_to']);

            $finalDestination = self::resolveCallbackDestination($user, $returnTo);

            if ($incomingCode !== null) {
                $_SESSION['last_processed_code'] = $incomingCode;
            }

            header('Location: ' . $finalDestination);
            exit;
        } catch (\Throwable $e) {
            error_log('Keycloak callback error: ' . $e->getMessage());
            self::clearSession();
            AuthResponder::renderLoginFailedPage();
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

        header('Location: index.php');
        exit;
    }

    // ────────────────────────────────────────────────────────────────────
    // Helpers internos
    // ────────────────────────────────────────────────────────────────────

    private static function handleAlreadyProcessedCode(?string $incomingCode): bool
    {
        if (
                $incomingCode !== null
                && isset($_SESSION['last_processed_code'])
                && hash_equals($_SESSION['last_processed_code'], $incomingCode)
                && self::getCurrentUser() !== null
        ) {
            $returnTo = $_SESSION['return_to'] ?? self::defaultDestinationForCurrentRole();
            $safe = self::resolveCallbackDestination(self::getCurrentUser() ?? [], $returnTo);
            header('Location: ' . $safe);
            return true;
        }

        return false;
    }

    private static function buildUserFromTokens(object $idToken, array $decodedAccess): array
    {
        $cedulaClaim = $_ENV['KEYCLOAK_CEDULA_CLAIM'] ?? 'cedula';
        $rawCedula   = (string) ($idToken->{$cedulaClaim} ?? $idToken->preferred_username ?? '');
        $cedulaDigits = preg_replace('/\D+/', '', $rawCedula);
        $cedula       = $cedulaDigits !== '' ? $cedulaDigits : $rawCedula;
        $username     = (string) ($idToken->preferred_username ?? '');

        return [
                'sub'                => $idToken->sub ?? '',
                'cedula'             => $cedula,
                'preferred_username' => $idToken->preferred_username ?? 'unknown',
                'email'              => $idToken->email ?? '',
                'given_name'         => $idToken->given_name ?? '',
                'family_name'        => $idToken->family_name ?? '',
                'name'               => $idToken->name ?? '',
                'client_roles'       => self::extractClientRoles($decodedAccess),
                'realm_roles'        => self::extractRealmRoles($decodedAccess),
                'external_roles'     => RoleProvider::getRolesForUser($cedula, $username),
                'idUsuario'          => RoleProvider::getUsuarioId($cedula, $username),
        ];
    }

    private static function resolveCallbackDestination(array $user, string $returnTo): string
    {
        $adminRole = $_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ADMIN_ACADEMICO';
        $userRole  = $_ENV['KEYCLOAK_ROLE_USER']  ?? 'SECRE_ACADEMICO';

        // 1. Respetar return_to si el rol es válido para ese destino
        if ($returnTo !== '') {
            $returnPath = basename(parse_url($returnTo, PHP_URL_PATH) ?? '');
            $validDestinations = [
                    'AdminPanel.php' => $adminRole,
                    'workspace.php'  => $userRole,
            ];

            if (
                    isset($validDestinations[$returnPath])
                    && in_array($validDestinations[$returnPath], $user['external_roles'] ?? [], true)
            ) {
                return $returnTo; // Retorno 1
            }
        }

        // 2. Fallbacks unificados (sin ternarios anidados y sin sumar returns extra)
        $hasAdmin = in_array($adminRole, $user['external_roles'] ?? [], true);
        $hasUser  = in_array($userRole, $user['external_roles'] ?? [], true);

        $fallbackDestination = 'logout.php'; // Destino por defecto

        if ($hasAdmin) {
            $fallbackDestination = 'AdminPanel.php';
        } elseif ($hasUser) {
            $fallbackDestination = 'workspace.php';
        }

        return $fallbackDestination; // Retorno 2
    }

    private static function defaultDestinationForCurrentRole(): string
    {
        $adminRole = $_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ADMIN_ACADEMICO';
        $userRole  = $_ENV['KEYCLOAK_ROLE_USER']  ?? 'SECRE_ACADEMICO';

        if (self::hasRole($adminRole)) {
            return 'AdminPanel.php';
        }

        if (self::hasRole($userRole)) {
            return 'workspace.php';
        }

        return 'login.php';
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

            $decoded = self::decodeJwtPayload((string) $newAccessToken);

            if (isset($_SESSION[self::SESSION_USER])) {
                $_SESSION[self::SESSION_USER]['client_roles'] = self::extractClientRoles($decoded);
                $_SESSION[self::SESSION_USER]['realm_roles']  = self::extractRealmRoles($decoded);

                $cedula   = $_SESSION[self::SESSION_USER]['cedula'] ?? '';
                $username = $_SESSION[self::SESSION_USER]['preferred_username'] ?? '';

                $_SESSION[self::SESSION_USER]['external_roles'] = RoleProvider::getRolesForUser(
                        $cedula,
                        $username
                );
            }

            $_SESSION[self::SESSION_TOKENS] = [
                    'access_token'  => $newAccessToken,
                    'refresh_token' => $newRefreshToken,
                    'id_token'      => $oidc->getIdToken(),
                    'expires_at'    => (int) ($decoded['exp'] ?? (time() + 300)),
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

        $payload = json_decode(
                base64_decode(strtr($parts[1], '-_', '+/')),
                true
        );

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

        try {
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }
        } catch (\Throwable $e) {
            error_log('Session regeneration failed: ' . $e->getMessage());
        }
    }
}