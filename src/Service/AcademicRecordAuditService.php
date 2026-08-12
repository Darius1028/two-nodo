<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicRecord;
use App\Entity\AcademicRecordAudit;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * Escribe las entradas de auditoría de AcademicRecord en el esquema AcademicoAUD.
 *
 * Usa DBAL directo (no ORM) para no re-entrar al UnitOfWork durante flush(),
 * exactamente como Hibernate Envers hace internamente con SQL.
 * La entidad AcademicRecordAudit es la única fuente de verdad del mapeo:
 * fromRecord() crea la entrada y toInsertArray() la serializa para DBAL.
 */
final class AcademicRecordAuditService
{
    public static function register(
        AcademicRecord $record,
        int $revisionType,
        Connection $connection
    ): void {
        if (!in_array($revisionType, [
            AcademicRecordAudit::INSERT,
            AcademicRecordAudit::UPDATE,
            AcademicRecordAudit::DELETE,
        ], true)) {
            throw new \InvalidArgumentException('El tipo de revisión no es válido.');
        }

        // OUTPUT evita depender de lastInsertId() con PDO_SQLSRV.
        $revisionId = $connection->fetchOne(
            'INSERT INTO AUD.REVINFO (REVTSTMP) OUTPUT INSERTED.REV VALUES (?)',
            [(string) round(microtime(true) * 1000)]
        );

        if ($revisionId === false) {
            throw new InvalidArgumentException('No se pudo generar la revisión de auditoría.');
        }

        $audit = AcademicRecordAudit::fromRecord($record, (int) $revisionId, $revisionType);

        $connection->insert('AcademicoAUD.RecordAcademico_AUD', $audit->toInsertArray());
    }
}