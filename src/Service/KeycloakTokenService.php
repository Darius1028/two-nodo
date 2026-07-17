<?php

declare(strict_types=1);

namespace App\Service;

use JsonException;
use RuntimeException;

final class KeycloakTokenService
{
    private string $tokenUrl;
    private string $clientId;
    private string $clientSecret;

    public function __construct(
        ?string $tokenUrl = null,
        ?string $clientId = null,
        ?string $clientSecret = null
    ) {
        $this->tokenUrl = $tokenUrl
            ?? ($_ENV['KEYCLOAK_SERVICE_TOKEN_URL'] ?? '');

        $this->clientId = $clientId
            ?? ($_ENV['KEYCLOAK_SERVICE_CLIENT_ID'] ?? '');

        $this->clientSecret = $clientSecret
            ?? ($_ENV['KEYCLOAK_SERVICE_CLIENT_SECRET'] ?? '');

        if (trim($this->tokenUrl) === '') {
            throw new RuntimeException(
                'No se configuró KEYCLOAK_SERVICE_TOKEN_URL.'
            );
        }

        if (trim($this->clientId) === '') {
            throw new RuntimeException(
                'No se configuró KEYCLOAK_SERVICE_CLIENT_ID.'
            );
        }

        if (trim($this->clientSecret) === '') {
            throw new RuntimeException(
                'No se configuró KEYCLOAK_SERVICE_CLIENT_SECRET.'
            );
        }
    }

    public function obtenerAccessToken(): string
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'La extensión cURL de PHP no está habilitada.'
            );
        }

        $curl = curl_init($this->tokenUrl);

        if ($curl === false) {
            throw new RuntimeException(
                'No se pudo inicializar cURL para Keycloak.'
            );
        }

        /*
         * Keycloak acepta client_id/client_secret mediante HTTP Basic.
         * Solamente enviamos grant_type en el formulario.
         */
        $postFields = http_build_query(
            [
                'grant_type' => 'client_credentials',
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->clientId . ':' . $this->clientSecret,

            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($curl);

        $statusCode = (int) curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

        $curlError = curl_error($curl);

        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException(
                'Error solicitando token a Keycloak: '
                . $curlError
            );
        }

        try {
            $decodedResponse = json_decode(
                $response,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Keycloak devolvió una respuesta JSON inválida.',
                0,
                $exception
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $error = $decodedResponse['error'] ?? 'error_desconocido';

            $description = $decodedResponse['error_description']
                ?? 'Keycloak rechazó la solicitud del token.';

            throw new RuntimeException(
                sprintf(
                    'Keycloak respondió HTTP %d. %s: %s',
                    $statusCode,
                    $error,
                    $description
                )
            );
        }

        $accessToken = $decodedResponse['access_token'] ?? null;

        if (
            !is_string($accessToken)
            || trim($accessToken) === ''
        ) {
            throw new RuntimeException(
                'Keycloak no devolvió access_token.'
            );
        }

        return trim($accessToken);
    }
}