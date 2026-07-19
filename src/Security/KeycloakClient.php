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

        $serverUrl    = trim((string)($_ENV['KEYCLOAK_SERVER_URL']     ?? ''));
        $realm        = trim((string)($_ENV['KEYCLOAK_REALM']         ?? ''));
        $clientId     = trim((string)($_ENV['KEYCLOAK_CLIENT_ID']     ?? ''));
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
        if ($redirectUri === '') {
            throw new RuntimeException('KEYCLOAK_REDIRECT_URI no está configurado.');
        }

        $issuer = sprintf('%s/realms/%s', rtrim($serverUrl, '/'), rawurlencode($realm));

        if ($clientSecret !== '') {
            // Cliente CONFIDENCIAL (tu caso: cj-cliente-prueba tiene
            // "Client Authenticator: Client Id and Secret" en Keycloak).
            self::$instance = new OpenIDConnectClient($issuer, $clientId, $clientSecret);
        } else {
            // Cliente PÚBLICO: sin secreto, protegido con PKCE.
            self::$instance = new OpenIDConnectClient($issuer, $clientId, '');
            self::$instance->setCodeChallengeMethod('S256');
        }

        self::$instance->addScope(['openid', 'profile', 'email']);
        self::$instance->setTimeOut(30);

        if ($appEnv !== 'prod') {
            self::$instance->setVerifyHost(false);
            self::$instance->setVerifyPeer(false);
        }

        return self::$instance;
    }
}