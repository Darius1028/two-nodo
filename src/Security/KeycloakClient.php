<?php
declare(strict_types=1);

namespace App\Security;

use Jumbojett\OpenIDConnectClient;

class KeycloakClient
{
    private static ?OpenIDConnectClient $instance = null;

    public static function get(): OpenIDConnectClient
    {
        if (self::$instance === null) {
            $issuer = rtrim($_ENV['KEYCLOAK_SERVER_URL'] ?? '', '/')
                . '/realms/' . ($_ENV['KEYCLOAK_REALM'] ?? 'academico');

            self::$instance = new OpenIDConnectClient(
                $issuer,
                $_ENV['KEYCLOAK_CLIENT_ID'] ?? '',
                $_ENV['KEYCLOAK_CLIENT_SECRET'] ?? ''
            );

            self::$instance->addScope(['openid', 'profile', 'email', 'roles']);
            self::$instance->setTimeOut(30);

            if (($_ENV['APP_ENV'] ?? 'prod') !== 'prod') {
                self::$instance->setVerifyHost(false);
                self::$instance->setVerifyPeer(false);
            }
        }
        return self::$instance;
    }
}