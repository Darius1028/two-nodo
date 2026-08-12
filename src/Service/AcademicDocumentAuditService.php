<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicDocument;
use App\Entity\AcademicDocumentAudit;
use App\Exception\ValidationException;
use Doctrine\DBAL\Connection;

final class AcademicDocumentAuditService
{
    public static function register(AcademicDocument $document, int $revisionType, Connection $connection): void
    {
        if (!in_array($revisionType, [
            AcademicDocumentAudit::INSERT,
            AcademicDocumentAudit::UPDATE,
            AcademicDocumentAudit::DELETE,
        ], true)) {
            throw new \InvalidArgumentException('El tipo de revisión no es válido.');
        }

        $revisionId = $connection->fetchOne(
            'INSERT INTO AUD.REVINFO (REVTSTMP) OUTPUT INSERTED.REV VALUES (?)',
            [(string) round(microtime(true) * 1000)]
        );
        if ($revisionId === false) {
            throw new ValidationException('No se pudo generar la revisión de auditoría.');
        }

        $connection->insert(
            'AcademicoAUD.DocumentoAcademico_AUD',
            AcademicDocumentAudit::fromDocument($document, (int) $revisionId, $revisionType)
        );
    }
}
