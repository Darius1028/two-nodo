<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\KeycloakClient;
use App\Security\RoleProvider;

/**
 * Gestiona la sesión del usuario autenticado vía Keycloak, la resolución
 * de roles desde la base institucional externa y las redirecciones de
 * login/logout/callback.
 *
 * Los roles NO vienen del token de Keycloak (Keycloak/LDAP solo autentica),
 * se consultan por cédula o username en la base institucional
 * PORTAL_APLICATIVOS_CJ (esquema ADM) vía RoleProvider.
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

    /**
     * id numérico de ADM.Usuario del usuario logueado, para usar en
     * AcademicRecord::setAuditoriaCreacion()/setAuditoriaModificacion().
     * Puede ser null si no se pudo resolver.
     */
    public static function getCurrentUserId(): ?int
    {
        $user = self::getCurrentUser();

        if ($user === null) {
            return null;
        }

        // Resolución lazy: si la sesión viene de antes de que se guardara
        // idUsuario (por ejemplo, el usuario no cerró sesión después de un
        // despliegue), se resuelve ahora y se guarda para las próximas
        // llamadas dentro de la misma sesión.
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

        error_log('[SecurityContext] idUsuario desde sesión: '
                . ($user['idUsuario'] === null ? 'null' : (string) $user['idUsuario']));

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
            self::renderAccessDeniedPage($role);
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
        // externa, resuelta por cédula. Ver RoleProvider::getRolesForUser().
        return in_array($role, $user['external_roles'] ?? [], true);
    }

    /**
     * Renderiza página de acceso denegado con redirección inteligente
     * según el rol que el usuario SÍ tiene asignado.
     */
    private static function renderAccessDeniedPage(string $requiredRole): void
    {
        $user = self::getCurrentUser();

        $adminRole = $_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ADMIN_ACADEMICO';
        $userRole  = $_ENV['KEYCLOAK_ROLE_USER']  ?? 'SECRE_ACADEMICO';

        $hasAdmin = self::hasRole($adminRole);
        $hasUser  = self::hasRole($userRole);

        // Determinar destino según rol disponible.
        // Si tiene ADMIN (con o sin USER) → AdminPanel.
        // Si solo tiene USER → workspace.
        // Si no tiene ninguno → logout (no debería llegar acá).
        if ($hasAdmin) {
            $redirectUrl  = 'AdminPanel.php';
            $redirectText = 'Ir al Panel de Administración';
        } elseif ($hasUser) {
            $redirectUrl  = 'workspace.php';
            $redirectText = 'Ir a Mesa de Consulta';
        } else {
            $redirectUrl  = 'logout.php';
            $redirectText = 'Cerrar sesión';
        }

        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Acceso Denegado</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #f5f7fa;
                    color: #333;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    margin: 0;
                }
                .box {
                    background: white;
                    border-radius: 10px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
                    padding: 40px;
                    max-width: 420px;
                    text-align: center;
                }
                .box span {
                    font-size: 48px;
                }
                h1 {
                    color: #003366;
                    font-size: 1.3rem;
                    margin: 15px 0 10px;
                }
                p {
                    color: #666;
                    font-size: 14px;
                    line-height: 1.5;
                    margin-bottom: 25px;
                }
                .buttons {
                    display: flex;
                    gap: 10px;
                    justify-content: center;
                }
                a {
                    display: inline-block;
                    background: #003366;
                    color: white;
                    text-decoration: none;
                    padding: 10px 24px;
                    border-radius: 6px;
                    font-size: 14px;
                    font-weight: 600;
                    transition: background 0.2s;
                }
                a:hover {
                    background: #004080;
                }
                a.secondary {
                    background: #f1f5f9;
                    color: #475569;
                }
                a.secondary:hover {
                    background: #e2e8f0;
                }
            </style>
        </head>
        <body>
        <div class="box">
            <span>🔒</span>
            <h1>Acceso Denegado</h1>
            <p>No tenés permiso para acceder a esta sección.<br>Tu usuario no tiene el rol requerido.</p>
            <div class="buttons">
                <a href="javascript:history.back()" class="secondary">← Volver atrás</a>
                <a href="logout.php">Cerrar sesión</a>
            </div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    // ────────────────────────────────────────────────────────────────────
    // Login / Logout / Callback
    // ────────────────────────────────────────────────────────────────────

    public static function redirectToKeycloak(): void
    {
        self::ensureSession();

        // FIX: si quien llama a esto es login.php o callback.php mismos
        // (por ejemplo, login.php invocado directo como el botón "Volver a
        // intentar"), REQUEST_URI apunta a esa misma página -- guardarla
        // como return_to arma un loop infinito: login.php -> Keycloak ->
        // callback.php -> vuelve a login.php -> Keycloak -> ... Estas dos
        // páginas nunca son un destino final válido, así que se filtran acá
        // sin importar desde dónde se invoque redirectToKeycloak().
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
            // Keycloak caído/inalcanzable, mal configurado, etc. Sin este
            // catch, la excepción de la librería OIDC (con rutas de
            // servidor y stack trace) se mostraba directo al usuario final.
            error_log('Keycloak redirect error: ' . $e->getMessage());
            self::renderLoginFailedPage();
        }
    }

    public static function handleCallback(): void
    {
        self::ensureSession();

        // Guard contra doble canje del mismo código de autorización -- se
        // mantiene como red de seguridad aunque la causa raíz del loop era
        // otra (ver redirectToKeycloak()): un código de autorización solo
        // se puede canjear una vez: si esta request repite un código que ya
        // canjeamos con éxito, no se vuelve a pegar contra Keycloak con un
        // código quemado -- se redirige directo al destino. session_start()
        // bloquea el archivo de sesión mientras está abierto, así que
        // aunque dos requests lleguen casi juntas, la segunda espera a que
        // la primera termine de escribir la sesión antes de leer esto.
        $incomingCode = is_string($_GET['code'] ?? null) ? $_GET['code'] : null;

        if (
                $incomingCode !== null
                && isset($_SESSION['last_processed_code'])
                && hash_equals($_SESSION['last_processed_code'], $incomingCode)
                && self::getCurrentUser() !== null
        ) {
            $returnTo = $_SESSION['return_to'] ?? self::defaultDestinationForCurrentRole();
            unset($_SESSION['return_to']);
            header('Location: ' . $returnTo);
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

            // El nombre exacto del claim con la cédula depende de cómo el
            // administrador de Keycloak mapeó el atributo de LDAP/AD hacia
            // el perfil del usuario (ej. "employeeID" -> claim "cedula").
            // Configurable por si no es "cedula" literal en el token.
            $cedulaClaim = $_ENV['KEYCLOAK_CEDULA_CLAIM'] ?? 'cedula';
            $rawCedula   = (string) ($idToken->{$cedulaClaim}
                    ?? $idToken->preferred_username ?? '');

            $cedulaDigits = preg_replace('/\D+/', '', $rawCedula);
            $cedula       = $cedulaDigits !== '' ? $cedulaDigits : $rawCedula;

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
                // Autorización real: roles resueltos por cédula O username
                // en la base externa (lo que primero coincida).
                    'external_roles'     => RoleProvider::getRolesForUser(
                            $cedula,
                            (string) ($idToken->preferred_username ?? '')
                    ),
                // id numérico de ADM.Usuario -- necesario para
                // idPersonaCrea/idPersonaModifica en la auditoría
                // institucional.
                    'idUsuario'          => RoleProvider::getUsuarioId(
                            $cedula,
                            (string) ($idToken->preferred_username ?? '')
                    ),
            ];

            $_SESSION[self::SESSION_USER] = $user;
            $_SESSION[self::SESSION_TOKENS] = [
                    'access_token'  => $accessToken,
                    'refresh_token' => $refreshToken,
                    'id_token'      => $oidc->getIdToken(),
                    'expires_at'    => (int) ($decodedAccess['exp']
                            ?? ($idToken->exp ?? time())),
            ];

            // ─ 1. Validar que el usuario tenga al menos un rol de la app ──
            $adminRole = $_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ADMIN_ACADEMICO';
            $userRole  = $_ENV['KEYCLOAK_ROLE_USER']  ?? 'SECRE_ACADEMICO';

            $hasAdmin = in_array($adminRole, $user['external_roles'] ?? [], true);
            $hasUser  = in_array($userRole,  $user['external_roles'] ?? [], true);

            if (!$hasAdmin && !$hasUser) {
                self::clearSession();
                self::renderNoRolesPage(
                        $user['preferred_username'] ?? 'unknown',
                        $adminRole,
                        $userRole
                );
                // no llega (renderNoRolesPage hace exit), pero por claridad:
                return;
            }

            // ── 2. Determinar destino final ──────────────────────────────
            // Mapa: página → rol mínimo requerido
            $validDestinations = [
                    'AdminPanel.php' => $adminRole,
                    'workspace.php'  => $userRole,
            ];

            $returnTo       = $_SESSION['return_to'] ?? '';
            $finalDestination = '';
            unset($_SESSION['return_to']);

            // Respetar return_to SOLO si el usuario tiene el rol para esa página
            if ($returnTo !== '') {
                $returnPath = basename(parse_url($returnTo, PHP_URL_PATH) ?? '');
                if (isset($validDestinations[$returnPath])) {
                    $requiredRole = $validDestinations[$returnPath];
                    if (in_array($requiredRole, $user['external_roles'] ?? [], true)) {
                        $finalDestination = $returnTo;
                    }
                }
            }

            // Fallback: si no hay return_to válido, elegir según rol
            // (ADMIN tiene prioridad porque es el panel de gestión)
            if ($finalDestination === '') {
                if ($hasAdmin) {
                    $finalDestination = 'AdminPanel.php';
                } elseif ($hasUser) {
                    $finalDestination = 'workspace.php';
                } else {
                    $finalDestination = 'logout.php';
                }
            }

            // ─ 3. Guardar código procesado y redirigir ──────────────────
            if ($incomingCode !== null) {
                $_SESSION['last_processed_code'] = $incomingCode;
            }

            header('Location: ' . $finalDestination);
            exit;
        } catch (\Throwable $e) {
            // El detalle técnico solo va al log del servidor -- el usuario
            // final ve una pantalla genérica, nunca el mensaje crudo de la
            // excepción (podría filtrar detalles de configuración interna).
            error_log('Keycloak callback error: ' . $e->getMessage());
            self::clearSession();
            self::renderLoginFailedPage();
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
    // Páginas de error HTML
    // ────────────────────────────────────────────────────────────────────

    /**
     * Página cuando el usuario autenticado no tiene NINGÚN rol asignado
     * para esta aplicación.
     */
    private static function renderNoRolesPage(
            string $username,
            string $adminRole,
            string $userRole
    ): void {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Acceso Denegado</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                    Oxygen, Ubuntu, Cantarell, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .container {
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                    padding: 40px;
                    max-width: 520px;
                    width: 100%;
                    text-align: center;
                }
                .icon {
                    width: 64px; height: 64px; margin: 0 auto 20px;
                    background: #fee2e2; border-radius: 50%;
                    display: flex; align-items: center; justify-content: center;
                }
                .icon svg { width: 32px; height: 32px; color: #dc2626; }
                h1 { font-size: 24px; color: #1e293b; margin-bottom: 16px; font-weight: 700; }
                .message {
                    color: #64748b; font-size: 15px; line-height: 1.6; margin-bottom: 24px;
                }
                .hint {
                    background: #eff6ff; border-left: 4px solid #3b82f6;
                    padding: 14px; border-radius: 4px; text-align: left;
                    margin-bottom: 24px; color: #1e40af; font-size: 14px;
                }
                .btn {
                    display: inline-flex; align-items: center; justify-content: center;
                    gap: 8px; padding: 12px 20px; border-radius: 8px;
                    text-decoration: none; font-weight: 600; font-size: 14px;
                    transition: all 0.2s; border: none; cursor: pointer;
                    background: #3b82f6; color: white;
                }
                .btn:hover {
                    background: #2563eb;
                    transform: translateY(-1px);
                    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
                }
            </style>
        </head>
        <body>
        <div class="container">
            <div class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667
                         1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16
                         c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>

            <h1>Acceso Denegado</h1>

            <p class="message">
                El usuario <strong><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></strong>
                no tiene ningún rol asignado para esta aplicación.
            </p>

            <div class="hint">
                Contactá al administrador para que te asigne el rol
                <strong><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></strong>
                o <strong><?= htmlspecialchars($adminRole, ENT_QUOTES, 'UTF-8') ?></strong>.
            </div>

            <a href="logout.php" class="btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Cerrar sesión
            </a>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    private static function renderLoginFailedPage(): void
    {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>No se pudo iniciar sesión</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #f5f7fa; color: #333;
                    display: flex; align-items: center; justify-content: center;
                    min-height: 100vh; margin: 0;
                }
                .box {
                    background: white; border-radius: 10px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
                    padding: 40px; max-width: 420px; text-align: center;
                }
                .box span { font-size: 48px; }
                h1 { color: #003366; font-size: 1.3rem; margin: 15px 0 10px; }
                p { color: #666; font-size: 14px; line-height: 1.5; margin-bottom: 25px; }
                a {
                    display: inline-block; background: #003366; color: white;
                    text-decoration: none; padding: 10px 24px; border-radius: 6px;
                    font-size: 14px; font-weight: 600;
                }
            </style>
        </head>
        <body>
        <div class="box">
            <span>🔒</span>
            <h1>No se pudo iniciar sesión</h1>
            <p>
                Ocurrió un problema al validar tus credenciales. Verificá tu usuario
                y contraseña, o intentá nuevamente en unos minutos. Si el problema
                continúa, contactá al área de soporte técnico.
            </p>
            <a href="login.php">Volver a intentar</a>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    // ────────────────────────────────────────────────────────────────────
    // Helpers internos
    // ────────────────────────────────────────────────────────────────────

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

        // Sin roles → ir al login (se redirigirá a Keycloak)
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

            // Al refrescar el token, los roles también pueden haber cambiado
            // en la base externa (ej. a alguien le sacan/agregan un rol).
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}