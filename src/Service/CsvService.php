<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\EntityManagerProvider;
use App\Entity\AcademicRecord;
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

    public static function importCSV(string $filePath, int $fallbackYear): array
    {
        $result = ['success' => false, 'imported' => 0, 'errors' => []];
        $em = EntityManagerProvider::get();
        $conn = $em->getConnection();
        $stream = null;

        // Un CSV de decenas de MB / cientos de miles de filas no entra en
        // los 120s por defecto de PHP. set_time_limit(0) quita el límite
        // desde el propio script; si igual corta, también hay que revisar
        // request_terminate_timeout (PHP-FPM) y fastcgi_read_timeout/
        // proxy_read_timeout (Nginx), que no dependen de este valor.
        set_time_limit(0);

        try {
            if (!file_exists($filePath) || !is_readable($filePath)) {
                throw new \RuntimeException('El archivo CSV no se puede leer.');
            }

            $content = (string)file_get_contents($filePath);
            $content = self::normalizeEncoding($content);

            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                throw new \RuntimeException('No se pudo abrir el stream en memoria.');
            }
            fwrite($stream, $content);
            rewind($stream);
            unset($content); // ya escrito en el stream; no hace falta la copia en memoria

            $separator = self::detectSeparator($stream);
            rewind($stream);

            $rawHeader = fgetcsv($stream, 10000, $separator);
            if (!$rawHeader || count($rawHeader) < 2) {
                throw new \RuntimeException('Formato de CSV inválido.');
            }

            $headerErrors = self::validateHeaderColumns($rawHeader);
            if (!empty($headerErrors)) {
                throw new \RuntimeException(
                    "Las columnas del archivo no coinciden con el formato requerido:\n"
                    . implode("\n", $headerErrors)
                    . "\n\nColumnas esperadas, en este orden exacto: "
                    . implode(', ', self::EXPECTED_HEADERS)
                );
            }

            $header = array_map([self::class, 'normalizeString'], $rawHeader);
            $schema = ConfigService::getColumnSchema();

            $classMetadata = $em->getClassMetadata(AcademicRecord::class);
            $tableName = $classMetadata->getTableName();
            $schemaName = $classMetadata->getSchemaName();
            $qualifiedTableName = $schemaName !== null && $schemaName !== ''
                ? $schemaName . '.' . $tableName
                : $tableName;

            $conn->beginTransaction();
            $count = 0;
            $pendingRows = [];

            while (($data = fgetcsv($stream, 10000, $separator)) !== false) {
                if (count($data) < count($header)) {
                    $data = array_pad($data, count($header), '');
                } elseif (count($data) > count($header)) {
                    $data = array_slice($data, 0, count($header));
                }
                $row = array_combine($header, $data);
                $cleanParams = self::buildInsertParams($row, $schema, $fallbackYear);

                // Se instancia la entidad SOLO para reutilizar su propia
                // lógica de defaults/formato (estado, idPersonaCrea='0',
                // ipCrea='', redondeo de nota/total a 2 decimales, etc.) --
                // no se persiste ni se hace flush(), así que no pega contra
                // la base. toArray() ya devuelve todo tipado y formateado
                // igual que si Doctrine lo hubiera insertado él mismo.
                $record = new AcademicRecord();
                $record->fill($cleanParams);
                $rowData = $record->toArray();

                // 'id' es IDENTITY (lo genera SQL Server). fechaCrea/
                // fechaModifica se resuelven con GETDATE() directo en el
                // SQL del batch, no acá, para no depender del formato
                // exacto que espera SqlServerDateTimeType.
                unset($rowData['id'], $rowData['fechaCrea'], $rowData['fechaModifica']);

                $pendingRows[] = $rowData;
                $count++;

                if (count($pendingRows) >= self::ROWS_PER_INSERT) {
                    self::bulkInsertBatch($conn, $classMetadata, $qualifiedTableName, $pendingRows);
                    $pendingRows = [];
                }
            }

            if (!empty($pendingRows)) {
                self::bulkInsertBatch($conn, $classMetadata, $qualifiedTableName, $pendingRows);
            }

            $conn->commit();

            $result['success'] = true;
            $result['imported'] = $count;
            self::logHistory('Importación CSV', "Se importaron $count registros.");
        } catch (Throwable $e) {
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
     * Inserta un lote de filas en un único statement multi-VALUES, sin
     * pasar por el UnitOfWork del ORM (evita el overhead de hidratar y
     * trackear 200k+ entidades, que además de lento se come memoria).
     *
     * @param array<int, array<string, mixed>> $rows Filas ya resueltas vía
     *        AcademicRecord::toArray() (sin 'id', 'fechaCrea' ni
     *        'fechaModifica' -- esas dos se completan acá con GETDATE()).
     */
    private static function bulkInsertBatch(
        \Doctrine\DBAL\Connection $conn,
        \Doctrine\ORM\Mapping\ClassMetadata $classMetadata,
        string $tableName,
        array $rows
    ): void {
        if (empty($rows)) {
            return;
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

        // GETDATE() es una expresión literal, no un parámetro: se resuelve
        // en el propio SQL Server al momento del INSERT. Evita depender del
        // formato exacto que produce/espera SqlServerDateTimeType.
        $singleRowPlaceholders = '(' . implode(',', array_fill(0, count($fields), '?')) . ',GETDATE(),GETDATE())';
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $tableName,
            implode(',', $columns),
            implode(',', array_fill(0, count($rows), $singleRowPlaceholders))
        );

        $params = [];
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $params[] = $row[$field];
            }
        }

        $conn->executeStatement($sql, $params);
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
        $path = ConfigService::getHistorialPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
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
        file_put_contents($path, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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

    private static function normalizeEncoding(string $content): string
    {
        $encoding = mb_detect_encoding($content, 'UTF-8, ISO-8859-1, Windows-1252', true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        } elseif ($encoding === false) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        return (string)preg_replace('/^\xEF\xBB\xBF/', '', $content);
    }

    private static function detectSeparator($stream): string
    {
        $line = fgets($stream);
        return ($line !== false && strpos($line, ';') !== false) ? ';' : ',';
    }
}