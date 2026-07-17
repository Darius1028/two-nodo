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

        $serverUrl = trim((string) getenv('KEYCLOAK_SERVER_URL'));
        $realm = trim((string) getenv('KEYCLOAK_REALM'));
        $clientId = trim((string) getenv('KEYCLOAK_CLIENT_ID'));
        $clientSecret = trim((string) getenv('KEYCLOAK_CLIENT_SECRET'));
        $redirectUri = trim((string) getenv('KEYCLOAK_REDIRECT_URI'));
        $appEnv = trim((string) (getenv('APP_ENV') ?: 'prod'));

        if ($serverUrl === '') {
            throw new RuntimeException(
                'KEYCLOAK_SERVER_URL no está configurado.'
            );
        }

        if ($realm === '') {
            throw new RuntimeException(
                'KEYCLOAK_REALM no está configurado.'
            );
        }

        if ($clientId === '') {
            throw new RuntimeException(
                'KEYCLOAK_CLIENT_ID no está configurado.'
            );
        }

        if ($redirectUri === '') {
            throw new RuntimeException(
                'KEYCLOAK_REDIRECT_URI no está configurado.'
            );
        }

        $issuer = sprintf(
            '%s/realms/%s',
            rtrim($serverUrl, '/'),
            rawurlencode($realm)
        );

        self::$instance = new OpenIDConnectClient(
            $issuer,
            $clientId,
            $clientSecret
        );

        // Esta línea es indispensable.
        self::$instance->setRedirectURL($redirectUri);

        self::$instance->addScope([
            'openid',
            'profile',
            'email',
        ]);

        self::$instance->setTimeout(30);

        if ($appEnv !== 'prod') {
            self::$instance->setVerifyHost(false);
            self::$instance->setVerifyPeer(false);
        }

        return self::$instance;
    }
}