<?php
declare(strict_types=1);

namespace App\Security;

use Jumbojett\OpenIDConnectClient;
use RuntimeException;

class KeycloakClient
{
    private static ?OpenIDConnectClient $instance = null;

    public static function get(): OpenIDConnectClient
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // FIX: getenv() puede devolver vacío bajo php-cgi aunque la variable
        // ya esté cargada por Dotenv. $_ENV es la fuente confiable acá,
        // consistente con el resto del proyecto.
        $serverUrl    = trim((string)($_ENV['KEYCLOAK_SERVER_URL']    ?? ''));
        $realm        = trim((string)($_ENV['KEYCLOAK_REALM']        ?? ''));
        $clientId     = trim((string)($_ENV['KEYCLOAK_CLIENT_ID']    ?? ''));
        $clientSecret = trim((string)($_ENV['KEYCLOAK_CLIENT_SECRET'] ?? ''));
        $redirectUri  = trim((string)($_ENV['KEYCLOAK_REDIRECT_URI']  ?? ''));
        $appEnv       = trim((string)($_ENV['APP_ENV'] ?? 'prod'));

        if ($serverUrl === '') {
            throw new RuntimeException('KEYCLOAK_SERVER_URL no está configurado.');
        }
        if ($realm === '') {
            throw new RuntimeException('KEYCLOAK_REALM no está configurado.');
        }
        if ($clientId === '') {
            throw new RuntimeException('KEYCLOAK_CLIENT_ID no está configurado.');
        }
        if ($clientSecret === '') {
            throw new RuntimeException(
                'KEYCLOAK_CLIENT_SECRET no está configurado. Sacalo de Keycloak: ' .
                'cliente "' . $clientId . '" → pestaña Credentials.'
            );
        }
        if ($redirectUri === '') {
            throw new RuntimeException('KEYCLOAK_REDIRECT_URI no está configurado.');
        }

        $issuer = sprintf('%s/realms/%s', rtrim($serverUrl, '/'), rawurlencode($realm));

        self::$instance = new OpenIDConnectClient($issuer, $clientId, $clientSecret);
        self::$instance->setRedirectURL($redirectUri);
        self::$instance->addScope(['openid', 'profile', 'email']);
        self::$instance->setTimeOut(30);

        if ($appEnv !== 'prod') {
            self::$instance->setVerifyHost(false);
            self::$instance->setVerifyPeer(false);
        }

        return self::$instance;
    }
}
