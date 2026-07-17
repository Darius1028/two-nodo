<?php

declare(strict_types=1);

namespace App\Service;

use JsonException;
use RuntimeException;

final class RepositorioDocumentalService
{
    private string $endpoint;

    public function __construct(?string $endpoint = null)
    {
        $endpointConfigurado = $endpoint
            ?? ($_ENV['REPOSITORIO_DOCUMENTAL_URL'] ?? null)
            ?? getenv('REPOSITORIO_DOCUMENTAL_URL')
            ?: null;

        if (
            !is_string($endpointConfigurado)
            || trim($endpointConfigurado) === ''
        ) {
            throw new RuntimeException(
                'No se configuró REPOSITORIO_DOCUMENTAL_URL.'
            );
        }

        $this->endpoint = trim($endpointConfigurado);
    }

    /**
     * @return array<string, mixed>
     */
    public function subirPdf(
        string $contenidoPdf,
        string $nombreArchivo,
        string $accessToken,
        string $ipOrigen,
        string $sistema = 'Permiso',
        string $modulo = 'Tramite',
        bool $requiereFirmado = true,
        bool $requiereIndex = true
    ): array {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'La extensión cURL de PHP no está habilitada.'
            );
        }

        if ($contenidoPdf === '') {
            throw new RuntimeException('El contenido del PDF está vacío.');
        }

        if ($accessToken === '') {
            throw new RuntimeException('No se recibió el access token.');
        }

        $nombreArchivo = basename($nombreArchivo);

        if (!str_ends_with(strtolower($nombreArchivo), '.pdf')) {
            $nombreArchivo .= '.pdf';
        }

        // Permite recibir "Bearer token" o solamente el token.
        $accessToken = preg_replace(
            '/^Bearer\s+/i',
            '',
            trim($accessToken)
        ) ?? '';

        $payload = [
            'sistema'          => $sistema,
            'modulo'           => $modulo,
            'requiereFirmado'  => $requiereFirmado ? 'S' : 'N',
            'requiereIndex'    => $requiereIndex ? 'S' : 'N',
            'ipOrigen'         => $ipOrigen,
            'nombreArchivo'    => $nombreArchivo,
            'base64Archivo'    => base64_encode($contenidoPdf),
        ];

        try {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'No se pudo construir el JSON de la solicitud.',
                0,
                $exception
            );
        }

        $curl = curl_init($this->endpoint);

        if ($curl === false) {
            throw new RuntimeException('No se pudo inicializar cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $json,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => 90,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,

            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken,
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
                'Error consumiendo RepositorioDocumentalService: '
                . $curlError
            );
        }

        $decodedResponse = json_decode($response, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $detalle = is_array($decodedResponse)
                ? json_encode($decodedResponse, JSON_UNESCAPED_UNICODE)
                : $response;

            throw new RuntimeException(
                sprintf(
                    'RepositorioDocumentalService respondió HTTP %d: %s',
                    $statusCode,
                    mb_substr((string) $detalle, 0, 1000)
                )
            );
        }

        if (!is_array($decodedResponse)) {
            return [
                'httpStatus' => $statusCode,
                'respuesta'  => $response,
            ];
        }

        return $decodedResponse;
    }
}