<?php
declare(strict_types=1);

namespace App\dto;

/**
 * Encapsula los parámetros necesarios para subir un documento,
 * resolviendo el code smell de exceso de parámetros (SonarQube S107).
 */
readonly class DocumentUploadDto
{
    public function __construct(
        public string $contenidoPdf,
        public string $nombreArchivo,
        public string $accessToken,
        public string $ipOrigen,
        public string $sistema = 'Permiso',
        public string $modulo = 'Tramite',
        public bool $requiereFirmado = true,
        public bool $requiereIndex = true
    ) {}
}