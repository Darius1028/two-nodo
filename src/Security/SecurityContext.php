<?php
declare(strict_types=1);

namespace App\Security;

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
        return $user['idUsuario'] ?? null;
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

        // FIX: si quien llama a esto es login.php o callback.php mismos
        // (por ejemplo, login.php invocado directo como el botón "Volver a
        // intentar"), REQUEST_URI apunta a esa misma página -- guardarla
        // como return_to arma un loop infinito: login.php -> Keycloak ->
        // callback.php -> vuelve a login.php -> Keycloak -> ... Estas dos
        // páginas nunca son un destino final válido, así que se filtran acá
        // sin importar desde dónde se invoque redirectToKeycloak().
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $requestPath = parse_url($requestUri, PHP_URL_PATH) ?? '';
        $invalidReturnTargets = ['login.php', 'callback.php'];
        $isInvalidReturnTarget = in_array(basename($requestPath), $invalidReturnTargets, true);

        $_SESSION['return_to'] = ($requestUri !== '' && !$isInvalidReturnTarget)
                ? $requestUri
                : 'workspace.php';

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
            $returnTo = $_SESSION['return_to'] ?? 'workspace.php';
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

            $decodedAccess = self::decodeJwtPayload((string)$accessToken);

            // El nombre exacto del claim con la cédula depende de cómo el
            // administrador de Keycloak mapeó el atributo de LDAP/AD hacia
            // el perfil del usuario (ej. "employeeID" -> claim "cedula").
            // Configurable por si no es "cedula" literal en el token.
            $cedulaClaim = $_ENV['KEYCLOAK_CEDULA_CLAIM'] ?? 'cedula';
            $rawCedula = (string)($idToken->{$cedulaClaim} ?? $idToken->preferred_username ?? '');

            // FIX: LDAP/AD puede entregar la cédula con guion
            // ("050287128-8"), mientras que ADM.Persona.identificacion la
            // guarda sin separadores ("0502871288"). Sin normalizar, el
            // join en RoleProvider nunca encuentra la fila y el usuario
            // queda siempre sin roles, sin ningún error visible.
            // Si al sacar todo lo que no es dígito queda vacío (ej. cuando
            // cae al fallback de preferred_username y ese no es numérico),
            // se conserva el valor original tal cual.
            $cedulaDigits = preg_replace('/\D+/', '', $rawCedula);
            $cedula = $cedulaDigits !== '' ? $cedulaDigits : $rawCedula;

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
                            (string)($idToken->preferred_username ?? '')
                    ),
                // id numérico de ADM.Usuario -- necesario para
                // idPersonaCrea/idPersonaModifica en la auditoría
                // institucional.
                    'idUsuario'          => RoleProvider::getUsuarioId(
                            $cedula,
                            (string)($idToken->preferred_username ?? '')
                    ),
            ];

            $_SESSION[self::SESSION_USER] = $user;
            $_SESSION[self::SESSION_TOKENS] = [
                    'access_token'  => $accessToken,
                    'refresh_token' => $refreshToken,
                    'id_token'      => $oidc->getIdToken(),
                    'expires_at'    => (int) ($decodedAccess['exp'] ?? ($idToken->exp ?? time())),
            ];

            $returnTo = $_SESSION['return_to'] ?? 'workspace.php';
            unset($_SESSION['return_to']);
            if ($incomingCode !== null) {
                $_SESSION['last_processed_code'] = $incomingCode;
            }
            header('Location: ' . $returnTo);
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

    private static function renderLoginFailedPage(): never
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
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #333; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .box { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); padding: 40px; max-width: 420px; text-align: center; }
                .box span { font-size: 48px; }
                h1 { color: #003366; font-size: 1.3rem; margin: 15px 0 10px; }
                p { color: #666; font-size: 14px; line-height: 1.5; margin-bottom: 25px; }
                a { display: inline-block; background: #003366; color: white; text-decoration: none; padding: 10px 24px; border-radius: 6px; font-size: 14px; font-weight: 600; }
            </style>
        </head>
        <body>
        <div class="box">
            <span>🔒</span>
            <h1>No se pudo iniciar sesión</h1>
            <p>Ocurrió un problema al validar tus credenciales. Verificá tu usuario y contraseña, o intentá nuevamente en unos minutos. Si el problema continúa, contactá al área de soporte técnico.</p>
            <a href="login.php">Volver a intentar</a>
        </div>
        </body>
        </html>
        <?php
        exit;
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
                $username = $_SESSION[self::SESSION_USER]['preferred_username'] ?? '';
                $_SESSION[self::SESSION_USER]['external_roles'] = RoleProvider::getRolesForUser($cedula, $username);
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