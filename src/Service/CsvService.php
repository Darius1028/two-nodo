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
     * 2100 parámetros por sentencia. Cada fila manda 23 parámetros (todas
     * las columnas de AcademicRecord::toArray() salvo id/fechaCrea/
     * fechaModifica, que van sin bind: id es IDENTITY y las fechas usan
     * GETDATE() literal). 2100/23 ≈ 91 -- se deja margen en 80.
     */
    private const ROWS_PER_INSERT = 80;

    /**
     * Filas por batch en el INSERT de auditoría AUD. La tabla tiene 28
     * columnas con parámetros → 2100 / 28 ≈ 75. Se deja margen en 70.
     */
    private const AUD_ROWS_PER_BATCH = 70;

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
        'N de Horas',
        'Fecha Inicio',
        'Facha Fin',
        'Cedula',
        'Nombre',
        'Apellido',
        'Email',
        'Total',
        'Aprueba',
        'Año',
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
            if ($actualName !== $expectedName) {
                $errors[] = sprintf(
                    'Columna %d: se encontró "%s", se esperaba "%s".',
                    $i + 1,
                    $actualName,
                    $expectedName
                );
            }
        }

        return $errors;
    }

    public static function importCSV(
        string $filePath,
        int $fallbackYear,
        int $idPersona = 0,
        string $ip = '',
        string $equipo = ''
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
            if (!file_exists($filePath) || !is_readable($filePath)) {
                throw new SystemException('El archivo CSV no se puede leer.');
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

            $conn->beginTransaction();

            // Pasamos los datos de auditoría en un array para no exceder
            // el límite de parámetros permitidos por SonarQube (S107)
            $count = self::processAndInsertRows(
                $stream,
                $separator,
                $header,
                $fallbackYear,
                [$idPersona, $ip, $equipo]
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
        }
        return $result;
    }

    /**
     * Procesa y guarda los registros del CSV en lotes para reducir la complejidad cognitiva.
     */
    private static function processAndInsertRows(
        $stream,
        string $separator,
        array $header,
        int $fallbackYear,
        array $auditData
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
        // Desempaquetar auditoría
        [$idPersona, $ip, $equipo] = $auditData;

        while (($data = fgetcsv($stream, 10000, $separator)) !== false) {
            if (count($data) < count($header)) {
                $data = array_pad($data, count($header), '');
            } elseif (count($data) > count($header)) {
                $data = array_slice($data, 0, count($header));
            }
            $row = array_combine($header, $data);
            $cleanParams = self::buildInsertParams($row, $schema, $fallbackYear);

            $record = new AcademicRecord();
            $record->fill($cleanParams);
            $record->setAuditoriaCreacion($idPersona, $ip, $equipo);
            $rowData = $record->toArray();

            unset($rowData['id'], $rowData['fechaCrea'], $rowData['fechaModifica']);

            $pendingRows[] = $rowData;
            $count++;

            if (count($pendingRows) >= self::ROWS_PER_INSERT) {
                $inserted = self::bulkInsertBatch($conn, $classMetadata, $qualifiedTableName, $pendingRows);
                self::bulkInsertAudit($conn, $inserted);
                $pendingRows = [];
            }
        }

        if (!empty($pendingRows)) {
            $inserted = self::bulkInsertBatch($conn, $classMetadata, $qualifiedTableName, $pendingRows);
            self::bulkInsertAudit($conn, $inserted);
        }

        return $count;
    }

    /**
     * Inserta un lote de filas en un único statement multi-VALUES, sin
     * pasar por el UnitOfWork del ORM (evita el overhead de hidratar y
     * trackear 200k+ entidades, que además de lento se come memoria).
     *
     * Usa OUTPUT INSERTED.* para capturar los datos reales escritos,
     * incluyendo el id IDENTITY y las fechas resueltas por GETDATE(),
     * necesarios para el INSERT de auditoría en AUD.
     *
     * @param array<int, array<string, mixed>> $rows Filas ya resueltas vía
     *        AcademicRecord::toArray() (sin 'id', 'fechaCrea' ni
     *        'fechaModifica' -- esas dos se completan acá con GETDATE()).
     * @return array<int, array<string, mixed>> Filas tal como quedaron en BD.
     */
    private static function bulkInsertBatch(
        \Doctrine\DBAL\Connection $conn,
        \Doctrine\ORM\Mapping\ClassMetadata $classMetadata,
        string $tableName,
        array $rows
    ): array {
        if (empty($rows)) {
            return [];
        }

        // Todas las filas traen exactamente las mismas claves (salen de
        // AcademicRecord::toArray() menos id/fechaCrea/fechaModifica), así
        // que alcanza con mirar la primera para fijar el orden de columnas.
        $fields = array_keys($rows[0]);
        $columns = array_map(
            static fn(string $field) => $classMetadata->getColumnName($field),
            $fields
        );
        $columns[] = $classMetadata->getColumnName('fechaCrea');
        $columns[] = $classMetadata->getColumnName('fechaModifica');

        // OUTPUT INSERTED.* devuelve cada fila tal como quedó en la tabla,
        // incluido el id generado por IDENTITY y las fechas de GETDATE().
        $outputCols = array_map(
            static fn(string $c) => 'INSERTED.' . $c,
            array_merge(['id'], $columns)
        );

        // GETDATE() es una expresión literal, no un parámetro: se resuelve
        // en el propio SQL Server al momento del INSERT. Evita depender del
        // formato exacto que produce/espera SqlServerDateTimeType.
        $singleRowPlaceholders = '(' . implode(',', array_fill(0, count($fields), '?')) . ',GETDATE(),GETDATE())';
        $sql = sprintf(
            'INSERT INTO %s (%s) OUTPUT %s VALUES %s',
            $tableName,
            implode(',', $columns),
            implode(',', $outputCols),
            implode(',', array_fill(0, count($rows), $singleRowPlaceholders))
        );

        $params = [];
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $params[] = $row[$field];
            }
        }

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
    }

    /**
     * Inserta en AUD.REVINFO y AcademicoAUD.RecordAcademico_AUD las filas devueltas
     * por bulkInsertBatch. Usa AcademicRecordAudit::fromRawRow() + toInsertArray()
     * para que la entidad sea la única fuente de verdad del mapeo de columnas.
     * Se procesa en chunks de AUD_ROWS_PER_BATCH para no superar el límite de
     * 2100 parámetros de SQL Server.
     *
     * @param array<int, array<string, mixed>> $insertedRows
     */
    private static function bulkInsertAudit(
        \Doctrine\DBAL\Connection $conn,
        array $insertedRows
    ): void {
        if (empty($insertedRows)) {
            return;
        }

        foreach (array_chunk($insertedRows, self::AUD_ROWS_PER_BATCH) as $chunk) {
            $revId = $conn->fetchOne(
                'INSERT INTO AUD.REVINFO (REVTSTMP) OUTPUT INSERTED.REV VALUES (?)',
                [(string) round(microtime(true) * 1000)]
            );

            if ($revId === false) {
                throw new ValidationException('No se pudo generar la revisión de auditoría para el batch CSV.');
            }

            $audits = array_map(
                static fn(array $row) => AcademicRecordAudit::fromRawRow($row, (int) $revId, AcademicRecordAudit::INSERT),
                $chunk
            );

            $audColumns = array_keys($audits[0]->toInsertArray());
            $placeholder = '(' . implode(',', array_fill(0, count($audColumns), '?')) . ')';

            $sql = sprintf(
                'INSERT INTO AcademicoAUD.RecordAcademico_AUD (%s) VALUES %s',
                implode(',', $audColumns),
                implode(',', array_fill(0, count($audits), $placeholder))
            );

            $params = [];
            foreach ($audits as $audit) {
                foreach ($audit->toInsertArray() as $value) {
                    $params[] = $value;
                }
            }

            $conn->executeStatement($sql, $params);
        }
    }

    public static function validateCSV(string $filePath): array
    {
        $result = ['success' => false, 'rows' => 0, 'errors' => []];
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $result['errors'][] = 'No se puede leer el archivo.';
            return $result;
        }
        $stream = fopen($filePath, 'r');
        if ($stream === false) {
            $result['errors'][] = 'No se pudo abrir el archivo.';
            return $result;
        }
        // Mismo detector que importCSV() -- si no coinciden, un archivo
        // separado por ';' se leería acá como una sola columna gigante y
        // el error de encabezado confundiría (parecería un problema de
        // nombres cuando en realidad es el separador).
        $separator = self::detectSeparator($stream);
        rewind($stream);

        $rawHeader = fgetcsv($stream, 10000, $separator);
        if (!$rawHeader || count($rawHeader) < 2) {
            fclose($stream);
            $result['errors'][] = 'Formato CSV inválido.';
            return $result;
        }

        $headerErrors = self::validateHeaderColumns($rawHeader);
        if (!empty($headerErrors)) {
            fclose($stream);
            $result['errors'] = array_merge(
                ['Las columnas del archivo no coinciden con el formato requerido:'],
                $headerErrors,
                ['Columnas esperadas, en este orden exacto: ' . implode(', ', self::EXPECTED_HEADERS)]
            );
            return $result;
        }

        $rows = 0;
        while (fgetcsv($stream, 10000, $separator) !== false) {
            $rows++;
        }
        fclose($stream);
        $result['success'] = true;
        $result['rows'] = $rows;
        return $result;
    }

    public static function exportCSV(int $year, string $outputPath, string $cedula = ''): array
    {
        $result = ['success' => false, 'errors' => []];
        try {
            $em = EntityManagerProvider::get();
            $qb = $em->createQueryBuilder()
                ->select('r')->from(AcademicRecord::class, 'r')
                ->orderBy('r.origen_tabla', 'DESC')
                ->addOrderBy('r.id', 'DESC');

            if ($cedula !== '') {
                $qb->where('r.cedula = :cedula')->setParameter('cedula', $cedula);
            } else {
                $qb->where('r.origen_tabla = :year')->setParameter('year', (string)$year);
            }

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
                throw new \RuntimeException("No se pudo crear el directorio de historial: {$dir}");
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
                throw new \RuntimeException("No se pudo escribir el historial: {$path}");
            }
        } catch (\Throwable $e) {
            // El historial es secundario: nunca debe convertir una operación exitosa en un fallo.
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
            'nota'           => ['nro de horas', 'horas', 'calificacion', 'nota', 'puntaje'],
            'total'          => ['total', 'suma', 'definitiva', 'calificacion total'],
            'materia'        => ['curso', 'evento', 'capacitacion', 'tema'],
            'fecha_inicio'   => ['inicio', 'desde', 'fecha de inicio'],
            'fecha_fin'      => ['fin', 'hasta', 'fecha de fin'],
            'grupo_objetivo' => ['grupo obj', 'grupo', 'dirigido a'],
            'email'          => ['correo', 'e-mail', 'mail', 'correo electronico'],
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
        $nombreCompleto = ($apellidoVal !== '' && stripos($nombreVal, $apellidoVal) === false)
            ? trim($nombreVal . ' ' . $apellidoVal)
            : $nombreVal;

        return [
            'cedula'         => (string)(self::findValue($row, $schema, 'cedula') ?? 'Sin Cédula'),
            'nombre'         => $nombreCompleto !== '' ? $nombreCompleto : 'Sin Nombre',
            'email'          => self::findValue($row, $schema, 'email'),
            'materia'        => (string)(self::findValue($row, $schema, 'materia') ?? ''),
            'nota'           => self::findValue($row, $schema, 'nota', true),
            'total'          => self::findValue($row, $schema, 'total', true),
            'periodo'        => self::findValue($row, $schema, 'periodo'),
            'proceso'        => self::findValue($row, $schema, 'proceso'),
            'grupo_objetivo' => self::findValue($row, $schema, 'grupo_objetivo'),
            'modalidad'      => self::findValue($row, $schema, 'modalidad'),
            'fecha_inicio'   => self::findValue($row, $schema, 'fecha_inicio'),
            'fecha_fin'      => self::findValue($row, $schema, 'fecha_fin'),
            'aprueba'        => self::findValue($row, $schema, 'aprueba'),
            'origen_tabla'   => $finalYear,
            'anio'           => (int)$finalYear,
        ];
    }

    /**
     * Abre el CSV como stream UTF-8 sin cargar el archivo entero en RAM.
     *
     * - UTF-8 (con o sin BOM): devuelve el archivo directo + offset post-BOM.
     * - Otro encoding: convierte en chunks de 64 KB a php://temp (vuelca a
     *   disco tras 2 MB, así archivos de 97 MB no saturan la RAM).
     *
     * @return array{0: resource, 1: int}  [stream, dataOffset]
     */
    private static function openStreamUtf8(string $filePath): array
    {
        $raw = fopen($filePath, 'rb');
        if ($raw === false) {
            throw new ValidationException('No se pudo abrir el archivo CSV.');
        }

        // 64 KB son suficientes para detectar encoding y BOM con fiabilidad.
        $sample = (string) fread($raw, 65536);
        rewind($raw);

        $hasBom   = str_starts_with($sample, "\xEF\xBB\xBF");
        $clean    = $hasBom ? substr($sample, 3) : $sample;
        $encoding = mb_detect_encoding($clean, 'UTF-8, ISO-8859-1, Windows-1252', true);

        // UTF-8 (o no detectado): usar el archivo directamente, sin copias.
        if ($encoding === false || $encoding === 'UTF-8') {
            $offset = $hasBom ? 3 : 0;
            fseek($raw, $offset);
            return [$raw, $offset];
        }

        // Otro encoding: convertir chunk a chunk a php://temp para no saturar RAM.
        // maxmemory:2097152 → vuelca al sistema de archivos tras 2 MB.
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
