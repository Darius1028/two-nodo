<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicRecord;
use App\Entity\AcademicRecordAudit;
use Doctrine\DBAL\Connection;

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
            throw new \InvalidArgumentException(
                'El tipo de revisión no es válido.'
            );
        }

        $id = $record->getId();
        if ($id === null) {
            throw new \RuntimeException(
                'No se puede auditar un registro sin identificador.'
            );
        }

        // OUTPUT evita depender de lastInsertId() con PDO_SQLSRV.
        $revisionId = $connection->fetchOne(
            'INSERT INTO AUD.REVINFO (REVTSTMP) '
            . 'OUTPUT INSERTED.REV VALUES (?)',
            [(string) round(microtime(true) * 1000)]
        );

        if ($revisionId === false) {
            throw new \RuntimeException(
                'No se pudo generar la revisión de auditoría.'
            );
        }

        $connection->insert('AUD.academic_records_AUD', [
            'id' => $id,
            'REV' => (int) $revisionId,
            'REVTYPE' => $revisionType,
            'cedula' => $record->getCedula(),
            'nombre' => $record->getNombre(),
            'email' => $record->getEmail(),
            'materia' => $record->getMateria(),
            'nota' => $record->getNota(),
            'total' => $record->getTotal(),
            'periodo' => $record->getPeriodo(),
            'anio' => $record->getAnio(),
            'origen_tabla' => $record->getOrigenTabla(),
            'proceso' => $record->getProceso(),
            'grupo_objetivo' => $record->getGrupoObjetivo(),
            'modalidad' => $record->getModalidad(),
            'fecha_inicio' => $record->getFechaInicio(),
            'fecha_fin' => $record->getFechaFin(),
            'aprueba' => $record->getAprueba(),
            'estado' => $record->getEstado(),
            'idPersonaCrea' => $record->getIdPersonaCrea(),
            'fechaCrea' => $record->getFechaCrea()->format('Y-m-d H:i:s.u'),
            'ipCrea' => $record->getIpCrea(),
            'equipoCrea' => $record->getEquipoCrea(),
            'idPersonaModifica' => $record->getIdPersonaModifica(),
            'fechaModifica' => $record->getFechaModifica()->format('Y-m-d H:i:s.u'),
            'ipModifica' => $record->getIpModifica(),
            'equipoModifica' => $record->getEquipoModifica(),
            'motivoModifica' => $record->getMotivoModifica(),
        ]);
    }
}