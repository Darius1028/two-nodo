<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\EntityManagerProvider;
use App\Entity\AcademicRecord;
use App\Entity\AcademicRecordAudit;
use App\Exception\SystemException;
use App\Exception\ValidationException;
use Throwable;

class CsvService
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

    /**
     * Encabezados EXACTOS que debe traer el CSV, en este orden -- definidos
     * por el funcional/dueño del proceso y confirmados contra un archivo
     * real de producción (Libro1.csv).
     */
    private const EXPECTED_HEADERS = [
        'Proceso',
        'Curso',
        'Grupo Objetivo',
        'Modalidad',
        'Nro. de Horas',
        'Fecha Inicio',
        'Facha Fin',
        'Cedula',
        'Nombre',
        'Apellido',
        'Email',
        'Genero',
        'Tipo',
        'Cargo',
        'Provincia',
        'Total',
        'Aprueba',
        'Año',
    ];

    /**
     * Variantes aceptadas por columna (además del nombre canónico de
     * EXPECTED_HEADERS). Se comparan normalizadas -- sin tildes, sin
     * mayúsculas y sin puntuación -- para que un archivo exportado desde
     * el Excel original no se rechace por diferencias cosméticas.
     *
     * La posición sigue siendo obligatoria: sólo se flexibiliza el nombre.
     *
     * @var array<string, string[]>
     */
    private const HEADER_VARIANTS = [
        'Nro. de Horas' => [
            'N de Horas',
            'Nro de Horas',
            'Numero de Horas',
            'Nro. de Horas Planificadas para desarrollar el curso',
            'Horas',
        ],
        'Cedula'   => ['Número de ID', 'Numero de ID', 'Identificacion', 'CI'],
        'Nombre'   => ['Nombres'],
        'Apellido' => ['Apellidos', 'Apellido(s)'],
        'Email'    => [
            'Dirección de correo personal',
            'Correo',
            'Correo personal',
            'Correo electronico',
        ],
        'Genero'   => ['Género', 'Sexo'],
        'Facha Fin' => ['Fecha Fin', 'Fecha de Fin'],
        'Fecha Inicio' => ['Fecha de Inicio'],
        'Año'      => ['AÑO', 'Anio', 'Ano', 'Periodo'],
    ];

    /**
     * Compara los encabezados reales del CSV contra EXPECTED_HEADERS,
     * posición por posición. Devuelve un array vacío si coincide, o la
     * lista de diferencias encontradas (para mostrárselas al usuario).
     *
     * @param array<int, mixed> $rawHeader
     * @return string[]
     */
    private static function validateHeaderColumns(array $rawHeader): array
    {
        $expected = self::EXPECTED_HEADERS;
        $actual = array_map(static fn($h) => trim((string)$h), $rawHeader);
        $errors = [];

        if (count($actual) !== count($expected)) {
            return [sprintf(
                'El archivo tiene %d columna(s), se esperaban %d: %s',
                count($actual),
                count($expected),
                implode(', ', $expected)
            )];
        }

        foreach ($expected as $i => $expectedName) {
            $actualName = $actual[$i] ?? '';

            if (self::headerMatches($actualName, $expectedName)) {
                continue;
            }

            $errors[] = sprintf(
                'Columna %d: se encontró "%s", se esperaba "%s".',
                $i + 1,
                $actualName,
                $expectedName
            );
        }

        return $errors;
    }

    /**
     * Compara un encabezado real contra el esperado, aceptando las
     * variantes declaradas en HEADER_VARIANTS. La comparación se hace
     * normalizada para tolerar tildes, mayúsculas y puntuación.
     */
    private static function headerMatches(string $actual, string $expected): bool
    {
        $normalize = static function (string $v): string {
            $v = self::normalizeString($v);
            return preg_replace('/[^a-z0-9]/', '', $v) ?? '';
        };

        $actualNorm = $normalize($actual);

        if ($actualNorm === $normalize($expected)) {
            return true;
        }

        foreach (self::HEADER_VARIANTS[$expected] ?? [] as $variant) {
            if ($actualNorm === $normalize($variant)) {
                return true;
            }
        }

        return false;
    }

    public static function importCSV(
        string $filePath,
        int $fallbackYear,
        int $idPersona = 0,
        string $ip = '',
        string $equipo = '',
        ?callable $onProgress = null
    ): array {
        $result = ['success' => false, 'imported' => 0, 'errors' => []];
        $em = EntityManagerProvider::get();
        $conn = $em->getConnection();
        $stream = null;

        set_time_limit(0);

        error_log('[CsvService] importCSV iniciado'
            . ' | idPersona=' . $idPersona
            . ' | ip="' . $ip . '"'
            . ' | equipo="' . $equipo . '"');

        try {
            // Validar tamaño antes de procesar
            $maxSize = 128 * 1024 * 1024; // 128MB
            if (filesize($filePath) > $maxSize) {
                throw new ValidationException('El archivo excede el tamaño máximo de 128MB');
            }

            [$stream, $dataOffset] = self::openStreamUtf8($filePath);
            $separator = self::detectSeparator($stream);
            fseek($stream, $dataOffset);

            $rawHeader = fgetcsv($stream, 10000, $separator);
            if (!$rawHeader || count($rawHeader) < 2) {
                throw new ValidationException('Formato de CSV inválido.');
            }

            $headerErrors = self::validateHeaderColumns($rawHeader);
            if (!empty($headerErrors)) {
                throw new ValidationException(
                    "Las columnas del archivo no coinciden con el formato requerido:\n"
                    . implode("\n", $headerErrors)
                    . "\n\nColumnas esperadas, en este orden exacto: "
                    . implode(', ', self::EXPECTED_HEADERS)
                );
            }

            $header = array_map([self::class, 'normalizeString'], $rawHeader);

            self::toggleIndexes($conn, disable: true);
            $conn->beginTransaction();

            $count = self::processAndInsertRows(
                $stream,
                $separator,
                $header,
                $fallbackYear,
                [$idPersona, $ip, $equipo],
                $onProgress
            );

            $conn->commit();

            $result['success'] = true;
            $result['imported'] = $count;
            self::logHistory('Importación CSV', "Se importaron $count registros.");
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $result['errors'][] = $e->getMessage();
            self::logHistory('Error Importación', 'Fallo al importar CSV: ' . $e->getMessage());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            try {
                self::toggleIndexes($conn, disable: false);
            } catch (\Throwable $e) {
                error_log(
                    '[CsvService] ALERTA: no se pudieron reconstruir los índices '
                    . 'de Academico.RecordAcademico tras la importación: ' . $e->getMessage()
                    . '. Ejecutar manualmente: ALTER INDEX ALL ON [Academico].[RecordAcademico] REBUILD;'
                );
            }
        }
        return $result;
    }

    private static function toggleIndexes(\Doctrine\DBAL\Connection $conn, bool $disable): void
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

    /**
     * Procesa y guarda los registros del CSV en lotes para reducir la complejidad cognitiva.
     */
    private static function processAndInsertRows(
        $stream,
        string $separator,
        array $header,
        int $fallbackYear,
        array $auditData,
        ?callable $onProgress = null
    ): int {
        $em = EntityManagerProvider::get();
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
            // Extracción para SonarQube: Limpieza del Array extraida a otra función
            $row = self::combineRowData($data, $header);
            $cleanParams = self::buildInsertParams($row, $schema, $fallbackYear);

            $record = new AcademicRecord();
            $record->fill($cleanParams);
            $record->setAuditoriaCreacion($idPersona, $ip, $equipo);
            $rowData = $record->toArray();

            unset($rowData['id'], $rowData['fechaCrea'], $rowData['fechaModifica']);

            $pendingRows[] = $rowData;
            $count++;

            if (count($pendingRows) >= self::ROWS_PER_INSERT) {
                self::bulkInsertBatchWithAudit($conn, $classMetadata, $qualifiedTableName, $pendingRows, $revId);
                $pendingRows = [];
                if ($onProgress !== null) {
                    $onProgress($count);
                }
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

    private static function combineRowData(array $data, array $header): array
    {
        $headerCount = count($header);
        $dataCount = count($data);

        if ($dataCount < $headerCount) {
            $data = array_pad($data, $headerCount, '');
        } elseif ($dataCount > $headerCount) {
            $data = array_slice($data, 0, $headerCount);
        }

        return array_combine($header, $data);
    }

    private static function createStagingTable(\Doctrine\DBAL\Connection $conn): void
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

    private static function dropStagingTable(\Doctrine\DBAL\Connection $conn): void
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
    private static function bulkInsertBatchWithAudit(
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

    private static function createRevision(\Doctrine\DBAL\Connection $conn): int
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

    public static function validateCSV(string $filePath): array
    {
        $result = ['success' => false, 'rows' => 0, 'errors' => []];

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $result['errors'][] = 'No se puede leer el archivo.';
            return $result; // Retorno 1
        }

        $stream = fopen($filePath, 'r');
        if ($stream === false) {
            $result['errors'][] = 'No se pudo abrir el archivo.';
            return $result; // Retorno 2
        }

        $separator = self::detectSeparator($stream);
        rewind($stream);

        $rawHeader = fgetcsv($stream, 10000, $separator);

        if (!$rawHeader || count($rawHeader) < 2) {
            $result['errors'][] = 'Formato CSV inválido.';
        } else {
            $headerErrors = self::validateHeaderColumns($rawHeader);
            if (!empty($headerErrors)) {
                $result['errors'] = array_merge(
                    ['Las columnas del archivo no coinciden con el formato requerido:'],
                    $headerErrors,
                    ['Columnas esperadas, en este orden exacto: ' . implode(', ', self::EXPECTED_HEADERS)]
                );
            } else {
                $rows = 0;
                while (fgetcsv($stream, 10000, $separator) !== false) {
                    $rows++;
                }
                $result['success'] = true;
                $result['rows'] = $rows;
            }
        }

        fclose($stream);
        return $result; // Retorno 3
    }

    public static function exportCSV(int $year, string $outputPath, string $cedula = ''): array
    {
        $result = ['success' => false, 'errors' => []];
        try {
            $em = EntityManagerProvider::get();
            $qb = $em->createQueryBuilder()
                ->select('r')->from(AcademicRecord::class, 'r')
                ->orderBy('r.anio', 'DESC')
                ->addOrderBy('r.id', 'DESC');

            if ($cedula !== '') {
                $qb->where('r.cedula = :cedula')->setParameter('cedula', $cedula);
            } else {
                $qb->where('r.anio = :year')->setParameter('year', (int)$year);
            }
            $qb->andWhere("r.estado != 'X'");

            $records = array_map(
                static fn(AcademicRecord $r) => $r->toArray(),
                $qb->getQuery()->getResult()
            );

            if (empty($records)) {
                $result['errors'][] = 'No hay registros para exportar.';
                return $result;
            }

            $fp = fopen($outputPath, 'w');
            if ($fp === false) {
                $result['errors'][] = 'No se pudo crear el archivo de salida.';
                return $result;
            }
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, array_keys($records[0]));
            foreach ($records as $rec) {
                fputcsv($fp, array_map(static fn($v) => $v === null ? '' : (string)$v, $rec));
            }
            fclose($fp);

            $result['success'] = true;
            self::logHistory('Exportación CSV', "Se exportaron " . count($records) . " registros del año $year.");
        } catch (Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }
        return $result;
    }

    public static function getHistory(int $limit = 50): array
    {
        $path = ConfigService::getHistorialPath();
        if (!file_exists($path)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_slice(array_reverse($decoded), 0, max(1, $limit));
    }

    public static function logHistory(string $action, string $details): void
    {
        try {
            $path = ConfigService::getHistorialPath();
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new SystemException("No se pudo crear el directorio de historial: {$dir}");
            }

            $history = file_exists($path)
                ? (json_decode((string)file_get_contents($path), true) ?? [])
                : [];
            if (!is_array($history)) {
                $history = [];
            }
            array_unshift($history, [
                'timestamp' => date('Y-m-d H:i:s'),
                'action'    => $action,
                'details'   => $details,
            ]);
            $history = array_slice($history, 0, 100);

            $json = json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $json, LOCK_EX) === false) {
                throw new SystemException("No se pudo escribir el historial: {$path}");
            }
        } catch (\Throwable $e) {
            error_log(sprintf('No se pudo registrar el historial (%s): %s', $action, $e->getMessage()));
        }
    }

    private static function normalizeString(mixed $str): string
    {
        $str = mb_strtolower(trim((string)$str), 'UTF-8');
        return strtr($str, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    private static function findValue(array $normalizedRow, array $schema, string $key, bool $isNumeric = false): mixed
    {
        $label = '';
        foreach ($schema as $col) {
            if (($col['key'] ?? $col['field'] ?? null) === $key) {
                $label = self::normalizeString((string)($col['label'] ?? ''));
                break;
            }
        }
        $keyNorm = self::normalizeString($key);
        $val = null;
        if ($label !== '' && isset($normalizedRow[$label])) {
            $val = $normalizedRow[$label];
        } elseif (isset($normalizedRow[$keyNorm])) {
            $val = $normalizedRow[$keyNorm];
        } else {
            $val = self::findByAlias($normalizedRow, $key, $keyNorm, $label);
        }
        if ($val === null || trim((string)$val) === '') {
            return null;
        }
        $val = trim((string)$val);
        if ($isNumeric) {
            $val = str_replace(',', '.', $val);
            $val = preg_replace('/[^0-9.\-]/', '', $val);
            return $val === '' ? null : (float)$val;
        }
        return $val;
    }

    private static function findByAlias(array $normalizedRow, string $key, string $keyNorm, string $label): ?string
    {
        $aliases = [
            'nombre'         => ['nombres', 'nombre completo', 'alumno', 'estudiante'],
            'apellido'       => ['apellidos', 'apellido paterno', 'apellido materno'],
            'cedula'         => ['identificacion', 'dni', 'documento', 'c.i.', 'ci'],
            'anio'           => ['ano', 'periodo', 'year', 'año'],

            // NOTA: 'nro de horas' y 'horas' estaban acá y hacían que las horas
            // del curso se guardaran como si fueran la calificación. Las horas
            // ahora tienen su propia columna (nro_horas).

            'total'          => ['total', 'nota', 'calificacion', 'puntaje', 'definitiva'],
            'nro_horas'      => ['nro de horas', 'n de horas', 'numero de horas', 'horas'],
            'curso'          => ['curso', 'materia', 'evento', 'capacitacion', 'tema'],
            'fecha_inicio'   => ['inicio', 'desde', 'fecha de inicio'],
            'fecha_fin'      => ['fin', 'hasta', 'fecha de fin'],
            'grupo_objetivo' => ['grupo objetivo', 'grupo obj', 'dirigido a'],
            'email'          => ['correo', 'e-mail', 'mail', 'correo electronico'],
            'genero'         => ['genero', 'sexo'],
            'tipo'           => ['tipo'],
            'cargo'          => ['cargo', 'funcion'],
            'provincia'      => ['provincia'],
        ];

        $searchTerms = isset($aliases[$key])
            ? array_merge([$keyNorm, $label], $aliases[$key])
            : [$keyNorm, $label];
        $searchTerms = array_filter($searchTerms);

        foreach ($normalizedRow as $rowKey => $rowVal) {
            foreach ($searchTerms as $term) {
                if ($term !== '' && strpos((string)$rowKey, (string)$term) !== false) {
                    return (string)$rowVal;
                }
            }
        }
        return null;
    }

    private static function buildInsertParams(array $row, array $schema, int $fallbackYear): array
    {
        $csvYear = self::findValue($row, $schema, 'anio') ?? self::findValue($row, $schema, 'periodo');
        $finalYear = !empty($csvYear) ? (string)$csvYear : (string)$fallbackYear;

        $nombreVal   = (string)(self::findValue($row, $schema, 'nombre') ?? '');
        $apellidoVal = (string)(self::findValue($row, $schema, 'apellido') ?? '');

        return [
            'cedula'         => (string)(self::findValue($row, $schema, 'cedula') ?? 'Sin Cédula'),
            'nombre'         => $nombreVal !== '' ? $nombreVal : 'Sin Nombre',
            'apellido'       => $apellidoVal !== '' ? $apellidoVal : null,
            'email'          => self::findValue($row, $schema, 'email'),
            'curso'          => (string)(self::findValue($row, $schema, 'curso') ?? ''),
            'nro_horas'      => self::findValue($row, $schema, 'nro_horas', true),
            'total'          => self::findValue($row, $schema, 'total', true),
            'periodo'        => self::findValue($row, $schema, 'periodo'),
            'proceso'        => self::findValue($row, $schema, 'proceso'),
            'grupo_objetivo' => self::findValue($row, $schema, 'grupo_objetivo'),
            'modalidad'      => self::findValue($row, $schema, 'modalidad'),
            'genero'         => self::findValue($row, $schema, 'genero'),
            'tipo'           => self::findValue($row, $schema, 'tipo'),
            'cargo'          => self::findValue($row, $schema, 'cargo'),
            'provincia'      => self::findValue($row, $schema, 'provincia'),
            'fecha_inicio'   => self::findValue($row, $schema, 'fecha_inicio'),
            'fecha_fin'      => self::findValue($row, $schema, 'fecha_fin'),
            'aprueba'        => self::findValue($row, $schema, 'aprueba'),
            'anio'           => (int)$finalYear,
        ];
    }

    private static function openStreamUtf8(string $filePath): array
    {
        $raw = fopen($filePath, 'rb');
        if ($raw === false) {
            throw new ValidationException('No se pudo abrir el archivo CSV.');
        }

        $sample = (string) fread($raw, 65536);
        rewind($raw);

        $hasBom   = str_starts_with($sample, "\xEF\xBB\xBF");
        $clean    = $hasBom ? substr($sample, 3) : $sample;
        $encoding = mb_detect_encoding($clean, 'UTF-8, ISO-8859-1, Windows-1252', true);

        if ($encoding === false || $encoding === 'UTF-8') {
            $offset = $hasBom ? 3 : 0;
            fseek($raw, $offset);
            return [$raw, $offset];
        }

        $temp = fopen('php://temp/maxmemory:2097152', 'r+');
        if ($temp === false) {
            fclose($raw);
            throw new ValidationException('No se pudo crear el stream temporal de conversión.');
        }

        if ($hasBom) {
            fseek($raw, 3);
        }

        while (!feof($raw)) {
            $chunk = fread($raw, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($temp, mb_convert_encoding($chunk, 'UTF-8', $encoding));
        }
        fclose($raw);
        rewind($temp);

        return [$temp, 0];
    }

    private static function detectSeparator($stream): string
    {
        $line = fgets($stream);
        return ($line !== false && strpos($line, ';') !== false) ? ';' : ',';
    }
}