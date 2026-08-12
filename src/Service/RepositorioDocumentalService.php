<?php
declare(strict_types=1);

namespace App\Service;

use App\dto\DocumentUploadDto;
use App\Exception\InvalidConfigurationException;
use App\Exception\SystemException;
use App\Exception\ValidationException;
use JsonException;

final class RepositorioDocumentalService
{
    private string $endpoint;

    public function __construct(?string $endpoint = null)
    {
        $endpointConfigurado = $endpoint
            ?? ($_ENV['REPOSITORIO_DOCUMENTAL_URL'] ?? null)
            ?? getenv('REPOSITORIO_DOCUMENTAL_URL')
            ?: null;

        if (!is_string($endpointConfigurado) || trim($endpointConfigurado) === '') {
            throw new InvalidConfigurationException('No se configuró REPOSITORIO_DOCUMENTAL_URL.');
        }

        $this->endpoint = trim($endpointConfigurado);
    }

    /**
     * @return array<string, mixed>
     */
    public function subirPdf(DocumentUploadDto $dto): array
    {
        $this->validarRequisitos($dto);

        $jsonPayload = $this->construirJsonPayload($dto);
        // Permite recibir "Bearer token" o solamente el token.
        $accessToken = preg_replace('/^Bearer\s+/i', '', trim($dto->accessToken)) ?? '';

        return $this->ejecutarPeticion($jsonPayload, $accessToken);
    }

    /**
     * Valida los requisitos iniciales antes de procesar el documento.
     */
    private function validarRequisitos(DocumentUploadDto $dto): void
    {
        if (!extension_loaded('curl')) {
            throw new SystemException('La extensión cURL de PHP no está habilitada.');
        }

        if ($dto->contenidoPdf === '') {
            throw new ValidationException('El contenido del PDF está vacío.');
        }

        if ($dto->accessToken === '') {
            throw new ValidationException('No se recibió el access token.');
        }
    }

    /**
     * Construye y serializa el payload para enviar al Repositorio Documental.
     */
    private function construirJsonPayload(DocumentUploadDto $dto): string
    {
        $nombreArchivo = basename($dto->nombreArchivo);
        if (!str_ends_with(strtolower($nombreArchivo), '.pdf')) {
            $nombreArchivo .= '.pdf';
        }

        $payload = [
            'tipo'             => $dto->tipo,
            'sistema'          => $dto->sistema,
            'modulo'           => $dto->modulo,
            'requiereFirmado'  => strtoupper($dto->requiereFirmado) === 'S' ? 'S' : 'N',
            'requiereIndex'    => strtoupper($dto->requiereIndex) === 'S' ? 'S' : 'N',
            'ipOrigen'         => $dto->ipOrigen,
            'nombreArchivo'    => $nombreArchivo,
            'base64Archivo'    => rawurlencode(base64_encode($dto->contenidoPdf)),
        ];

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new SystemException('No se pudo construir el JSON de la solicitud.', 0, $exception);
        }
    }

    /**
     * Ejecuta la petición cURL y procesa la respuesta devuelta.
     */
    private function ejecutarPeticion(string $jsonPayload, string $accessToken): array
    {
        $curl = curl_init($this->endpoint);
        if ($curl === false) {
            throw new SystemException('No se pudo inicializar cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $jsonPayload,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => 90,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);

        $response = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new SystemException('Error consumiendo RepositorioDocumentalService: ' . $curlError);
        }

        return $this->procesarRespuesta($response, $statusCode);
    }

    /**
     * Valida el código HTTP de respuesta y decodifica el resultado del servicio.
     */
    private function procesarRespuesta(string $response, int $statusCode): array
    {
        $decodedResponse = json_decode($response, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $detalle = is_array($decodedResponse)
                ? json_encode($decodedResponse, JSON_UNESCAPED_UNICODE)
                : $response;

            throw new SystemException(
                sprintf(
                    'RepositorioDocumentalService respondió HTTP %d: %s',
                    $statusCode,
                    mb_substr((string) $detalle, 0, 1000)
                )
            );
        }

        if (!is_array($decodedResponse)) {
            // Este servicio responde el identificador documental como texto plano
            $identificador = trim(is_string($decodedResponse) ? $decodedResponse : $response);

            return [
                'httpStatus'      => $statusCode,
                'uuidRepositorio' => mb_substr($identificador, 0, 100),
                'respuesta'       => $response,
            ];
        }

        return $decodedResponse;
    }

    /**
     * Obtiene el identificador documental sin acoplar el sistema a una sola
     * envoltura JSON del servicio (algunos ambientes responden dentro de data).
     */
    public static function extraerUuid(array $response): string
    {
        $keys = ['uuidRepositorio', 'uuid', 'uuidDocumento', 'documentUuid', 'idDocumento'];
        foreach ($keys as $key) {
            $value = $response[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 100);
            }
        }

        foreach ($response as $value) {
            if (is_array($value)) {
                $uuid = self::extraerUuid($value);
                if ($uuid !== '') {
                    return $uuid;
                }
            }
        }

        return '';
    }
}
