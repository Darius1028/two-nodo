<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\SystemException;
use App\Security\KeycloakClient;
use Throwable;

/**
 * Token de SERVICIO (flujo OAuth2 client_credentials) para llamar a APIs
 * internas institucionales -- hoy, el Repositorio Documental desde
 * api.php?action=archive_pdf.
 *
 * A diferencia de SecurityContext::getAccessToken(), que devuelve el token
 * del usuario logueado (flujo authorization code), acá se pide un token
 * propio de la aplicación, autenticándose con su client_id/client_secret
 * directamente contra Keycloak. Requiere que el cliente de Keycloak
 * (KEYCLOAK_CLIENT_ID/KEYCLOAK_CLIENT_SECRET) tenga "Service Accounts
 * Enabled" habilitado -- si no lo tiene, Keycloak responde con un error de
 * client_credentials inválido.
 *
 * NOTA: esta clase no existía en el repositorio -- solo se la referenciaba
 * desde api.php?action=archive_pdf (y desde un comentario del Dockerfile),
 * por eso esa acción fallaba con "Class not found". Esta es una primera
 * implementación con el flujo estándar; falta verificar contra el Keycloak
 * real de la institución:
 *
 *   - Hay un issue conocido de jumbojett/openid-connect-php donde Keycloak
 *     rechaza con "invalid scope" el scope que la librería manda por
 *     defecto en requestClientCredentialsToken() (arrastra los scopes de
 *     KeycloakClient::get(), pensados para el login interactivo: openid,
 *     profile, email -- "profile"/"email" no suelen ser válidos para un
 *     service account). Si falla con ese error, probar limpiando los scopes
 *     a solo 'openid' antes de pedir el token (ver comentario en el código).
 */
final class KeycloakTokenService
{
    private ?string $cachedToken = null;
    private int $expiresAt = 0;

    public function obtenerAccessToken(): string
    {
        if ($this->cachedToken !== null && time() < $this->expiresAt) {
            return $this->cachedToken;
        }

        $oidc = KeycloakClient::get();

        try {
            // Si Keycloak responde "invalid scope" para este client, probar
            // reemplazando la línea de abajo por:
            //   $oidc->addScope(['openid'], true); // true = reemplaza en vez de agregar
            // (requiere que el método addScope() de tu versión de la
            // librería soporte el segundo parámetro; si no, ver el issue
            // jumbojett/OpenID-Connect-PHP#392 para el workaround completo).
            $response = $oidc->requestClientCredentialsToken();
        } catch (Throwable $e) {
            throw new SystemException(
                'No se pudo obtener el token de servicio de Keycloak: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $accessToken = $response->access_token ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new SystemException(
                'Keycloak no devolvió un access_token de servicio válido. '
                . 'Verificar que el cliente tenga "Service Accounts Enabled".'
            );
        }

        $expiresIn = is_numeric($response->expires_in ?? null) ? (int)$response->expires_in : 60;
        // Margen de 10s para no usar un token que expira justo al llegar
        // al Repositorio Documental.
        $this->expiresAt = time() + max(10, $expiresIn - 10);
        $this->cachedToken = $accessToken;

        return $accessToken;
    }
}