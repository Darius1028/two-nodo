<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicRecord;
use App\Entity\AcademicRecordAudit;
use App\Exception\ValidationException;

/**
 * Inserción por lotes de registros de AcademicRecord con auditoría, y
 * operaciones de base de datos asociadas (índices, staging, revisión).
 * Extraído de CsvService para reducir su tamaño.
 */
final class CsvImportProcessor
{
    /**
     * Filas agrupadas por statement de INSERT. Límite práctico de SQL Server:
     * 2100 parámetros por sentencia. Cada fila manda 28 parámetros (todas
     * las columnas de AcademicRecord::toArray() salvo id/fechaCrea/
     * fechaModifica, que van sin bind: id es IDENTITY y las fechas usan
     * GETDATE() literal), más 1 parámetro único para la revisión de
     * auditoría (el lote, no por fila). 60 filas x 28
     * + 1 = 1681 parámetros -- deja margen razonable bajo el límite.
     *
     * OJO: si se agregan columnas a la entidad hay que recalcular esto,
     * o el INSERT falla con "Too many parameters".
     */
    private const ROWS_PER_INSERT = 60;

    /** Cada cuántas filas se deja rastro del avance en el log de PHP. */
    private const PROGRESS_EVERY = 5000;

    private function __construct()
    {
    }

    /**
     * Procesa y guarda los registros del CSV en lotes para reducir la complejidad cognitiva.
     *
     * La inspección de seguridad se hace inline (una sola pasada sobre el
     * archivo): contenido peligroso, validación por columna y límite de filas.
     * Si algo falla se lanza ValidationException; el llamador hace rollback.
     */
    public static function processAndInsertRows(
        $stream,
        string $separator,
        array $header,
        int $fallbackYear,
        array $auditData,
        string $filePath,
        ?callable $onProgress = null
    ): int {
        $em = \App\Core\EntityManagerProvider::get();
        $conn = $em->getConnection();
        $schema = ConfigService::getColumnSchema();

        $classMetadata = $em->getClassMetadata(AcademicRecord::class);
        $tableName = $classMetadata->getTableName();
        $schemaName = $classMetadata->getSchemaName();
        $qualifiedTableName = $schemaName !== null && $schemaName !== ''
            ? $schemaName . '.' . $tableName
            : $tableName;

        $count = 0;
        $pendingRows = [];
        [$idPersona, $ip, $equipo] = $auditData;

        $revId = self::createRevision($conn);
        $inicio = microtime(true);

        // Extracción para SonarQube: Se delegó la creación de la tabla a otra función
        self::createStagingTable($conn);

        while (($data = fgetcsv($stream, 10000, $separator)) !== false) {
            $count++;

            // Límite de filas (seguridad): se corta temprano y el rollback
            // descarta lo insertado hasta acá.
            if ($count > CsvService::MAX_IMPORT_ROWS) {
                throw new ValidationException(sprintf(
                    'El archivo supera el límite de %d filas permitidas.',
                    CsvService::MAX_IMPORT_ROWS
                ));
            }

            // Extracción para SonarQube: Limpieza del Array extraida a otra función
            $row = CsvRowMapper::combineRowData($data, $header);

            self::assertRowSafe($row, $schema, $count, $filePath, $idPersona, $ip, $equipo);

            $cleanParams = CsvRowMapper::buildInsertParams($row, $schema, $fallbackYear);

            $record = new AcademicRecord();
            $record->fill($cleanParams);
            $record->setAuditoriaCreacion($idPersona, $ip, $equipo);
            $rowData = $record->toArray();

            unset($rowData['id'], $rowData['fechaCrea'], $rowData['fechaModifica']);

            $pendingRows[] = $rowData;

            if (count($pendingRows) >= self::ROWS_PER_INSERT) {
                self::flushBatch($conn, $classMetadata, $qualifiedTableName, $pendingRows, $revId, $count, $onProgress);
                $pendingRows = [];
            }

            if ($count % self::PROGRESS_EVERY === 0) {
                error_log(sprintf(
                    '[CsvService] avance: %d filas procesadas en %.1f s',
                    $count,
                    microtime(true) - $inicio
                ));
            }
        }

        if (!empty($pendingRows)) {
            self::bulkInsertBatchWithAudit($conn, $classMetadata, $qualifiedTableName, $pendingRows, $revId);
        }

        // Extracción para SonarQube: El drop de la tabla se encapsuló en un método aparte
        self::dropStagingTable($conn);

        if ($onProgress !== null) {
            $onProgress($count);
        }

        error_log(sprintf(
            '[CsvService] importación finalizada: %d filas en %.1f s (revisión %d)',
            $count,
            microtime(true) - $inicio,
            $revId
        ));

        return $count;
    }

    /**
     * Valida una fila antes de insertarla: contenido peligroso y reglas por
     * columna. Lanza ValidationException si la fila no es segura/válida.
     */
    private static function assertRowSafe(
        array $row,
        array $schema,
        int $count,
        string $filePath,
        int $idPersona,
        string $ip,
        string $equipo
    ): void {
        // Contenido peligroso en las celdas (XSS almacenado / fórmulas CSV)
        $suspicious = CsvContentInspector::findSuspiciousCells($row, $count);
        if (!empty($suspicious)) {
            CsvService::alertSuspiciousImport($filePath, $idPersona, $ip, $equipo, $count, $suspicious);
            throw new ValidationException(
                'El archivo contiene contenido potencialmente peligroso (etiquetas HTML, '
                . 'javascript:, onerror o fórmulas CSV) y fue bloqueado. Se notificó al área '
                . 'de seguridad con el hash del archivo, el usuario y el conteo de filas.'
            );
        }

        // Validación por columna (tipo, longitud, caracteres)
        $rowErrors = CsvContentInspector::validateRowCells($row, $schema, $count);
        if (!empty($rowErrors)) {
            throw new ValidationException(
                'El archivo tiene datos que no cumplen el esquema esperado (fila ' . $count . ' = primera fila de datos):' . "\n"
                . implode("\n", array_slice($rowErrors, 0, 25))
            );
        }
    }

    /**
     * Inserta el lote pendiente y reporta el avance si hay callback.
     */
    private static function flushBatch(
        \Doctrine\DBAL\Connection $conn,
        \Doctrine\ORM\Mapping\ClassMetadata $classMetadata,
        string $tableName,
        array $pendingRows,
        int $revId,
        int $count,
        ?callable $onProgress
    ): void {
        self::bulkInsertBatchWithAudit($conn, $classMetadata, $tableName, $pendingRows, $revId);
        if ($onProgress !== null) {
            $onProgress($count);
        }
    }

    public static function toggleIndexes(\Doctrine\DBAL\Connection $conn, bool $disable): void
    {
        $accion = $disable ? 'DISABLE' : 'REBUILD';

        $indices = $conn->fetchFirstColumn(
            "SELECT i.name FROM sys.indexes i
             WHERE i.object_id = OBJECT_ID('Academico.RecordAcademico')
               AND i.type <> 0        -- excluye heap
               AND i.is_primary_key = 0
               AND i.name IS NOT NULL"
        );

        foreach ($indices as $indexName) {
            $conn->executeStatement(
                sprintf(
                    'ALTER INDEX [%s] ON [Academico].[RecordAcademico] %s',
                    str_replace(']', ']]', $indexName),
                    $accion
                )
            );
        }

        error_log(sprintf(
            '[CsvService] Índices de RecordAcademico: %s aplicado a %d índice(s).',
            $accion,
            count($indices)
        ));
    }

    public static function createStagingTable(\Doctrine\DBAL\Connection $conn): void
    {
        $colDefs = $conn->fetchAllAssociative(
            "SELECT COLUMN_NAME, DATA_TYPE,
                    CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION, NUMERIC_SCALE,
                    IS_NULLABLE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = 'Academico'
               AND TABLE_NAME   = 'RecordAcademico'
               AND COLUMN_NAME  <> 'id'
             ORDER BY ORDINAL_POSITION"
        );
        $stageCols = array_map(static function (array $col): string {
            $type = strtoupper($col['DATA_TYPE']);
            $def  = '[' . $col['COLUMN_NAME'] . ']';
            if (in_array($type, ['CHAR','VARCHAR','NCHAR','NVARCHAR'], true)) {
                $len = $col['CHARACTER_MAXIMUM_LENGTH'];
                $def .= ' ' . $type . '(' . ($len === null || $len == -1 ? 'MAX' : $len) . ')';
            } elseif (in_array($type, ['DECIMAL','NUMERIC'], true)) {
                $def .= ' ' . $type . '(' . $col['NUMERIC_PRECISION'] . ',' . $col['NUMERIC_SCALE'] . ')';
            } else {
                $def .= ' ' . $type;
            }
            $def .= $col['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
            return $def;
        }, $colDefs);

        $conn->executeStatement(
            "IF OBJECT_ID('tempdb..#stage_auditoria') IS NOT NULL DROP TABLE [#stage_auditoria];
             CREATE TABLE [#stage_auditoria] (
                 [id] INT NOT NULL,
                 " . implode(",\n                 ", $stageCols) . "
             );"
        );
    }

    public static function dropStagingTable(\Doctrine\DBAL\Connection $conn): void
    {
        try {
            $conn->executeStatement("IF OBJECT_ID('tempdb..#stage_auditoria') IS NOT NULL DROP TABLE #stage_auditoria;");
        } catch (\Throwable $e) {
            error_log('[CsvService] no se pudo limpiar #stage_auditoria (no crítico): ' . $e->getMessage());
        }
    }

    /**
     * Inserta un lote de filas y escribe su auditoría en UN SOLO viaje de red.
     */
    public static function bulkInsertBatchWithAudit(
        \Doctrine\DBAL\Connection $conn,
        \Doctrine\ORM\Mapping\ClassMetadata $classMetadata,
        string $tableName,
        array $rows,
        int $revId
    ): void {
        if (empty($rows)) {
            return;
        }

        $fields = array_keys($rows[0]);
        $columns = array_map(
            static fn(string $field) => $classMetadata->getColumnName($field),
            $fields
        );
        $columns[] = $classMetadata->getColumnName('fechaCrea');
        $columns[] = $classMetadata->getColumnName('fechaModifica');

        $singleRowPlaceholders = '(' . implode(',', array_fill(0, count($fields), '?')) . ',GETDATE(),GETDATE())';

        $insertPrincipal = sprintf(
            'TRUNCATE TABLE [#stage_auditoria];
             INSERT INTO %s (%s) OUTPUT INSERTED.* INTO [#stage_auditoria] VALUES %s;',
            $tableName,
            implode(',', $columns),
            implode(',', array_fill(0, count($rows), $singleRowPlaceholders))
        );

        $insertAuditoria = sprintf(
            'INSERT INTO AcademicoAUD.RecordAcademico_AUD (id, REV, REVTYPE, %s)
             SELECT id, ?, %d, %s FROM [#stage_auditoria];',
            implode(',', $columns),
            AcademicRecordAudit::INSERT,
            implode(',', $columns)
        );

        $sql = $insertPrincipal . "\n" . $insertAuditoria;

        $params = [];
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $params[] = $row[$field];
            }
        }
        $params[] = $revId;

        $conn->executeStatement($sql, $params);
    }

    public static function createRevision(\Doctrine\DBAL\Connection $conn): int
    {
        $revId = $conn->fetchOne(
            'INSERT INTO AUD.REVINFO (REVTSTMP) OUTPUT INSERTED.REV VALUES (?)',
            [(string) round(microtime(true) * 1000)]
        );

        if ($revId === false) {
            throw new ValidationException('No se pudo generar la revisión de auditoría para la importación CSV.');
        }

        return (int) $revId;
    }
}
