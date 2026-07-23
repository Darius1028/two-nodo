<?php
declare(strict_types=1);

namespace App\Entity;

final class AcademicDocumentAudit
{
    public const INSERT = 0;
    public const UPDATE = 1;
    public const DELETE = 2;

    /** @return array<string, mixed> */
    public static function fromDocument(AcademicDocument $document, int $revision, int $revisionType): array
    {
        $id = $document->getId();
        if ($id === null) {
            throw new \InvalidArgumentException('No se puede auditar un documento sin identificador.');
        }

        return [
            'idDocumento' => $id,
            'REV' => $revision,
            'REVTYPE' => $revisionType,
            'idRecordAcademico' => $document->getAcademicRecord()->getId(),
            'uuidRepositorio' => $document->getRepositoryUuid(),
            'nombreArchivo' => $document->getFileName(),
            'estado' => $document->getEstado(),
            'idPersonaCrea' => $document->getIdPersonaCrea(),
            'fechaCrea' => $document->getFechaCrea()->format('Y-m-d\TH:i:s.v'),
            'ipCrea' => $document->getIpCrea(),
            'equipoCrea' => $document->getEquipoCrea(),
            'idPersonaModifica' => $document->getIdPersonaModifica(),
            'fechaModifica' => $document->getFechaModifica()->format('Y-m-d\TH:i:s.v'),
            'ipModifica' => $document->getIpModifica(),
            'equipoModifica' => $document->getEquipoModifica(),
            'motivoModifica' => $document->getMotivoModifica(),
        ];
    }
}
