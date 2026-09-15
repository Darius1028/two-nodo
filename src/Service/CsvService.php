<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\EntityManagerProvider;
use App\Entity\AcademicRecord;
use App\Exception\InvalidConfigurationException;
use App\Exception\SystemException;
use App\Exception\ValidationException;
use App\Security\SecurityAlertService;
use Throwable;

/**
 * Fachada pública del procesamiento de CSV: importación, validación,
 * exportación e historial.
 *
 * La lógica pesada está delegada en:
 *  - CsvRowMapper: mapeo de filas del CSV a parámetros de inserción.
 *  - CsvContentInspector: encabezados, reglas de contenido y validación por
 *    columna.
 *  - CsvImportProcessor: inserción por lotes con auditoría.
 */
class CsvService
{
    /** Tamaño máximo del archivo CSV a importar (bytes). */
    public const MAX_FILE_SIZE = 128 * 1024 * 1024; // 128MB

    /** Máximo de filas de datos permitidas por archivo. */
    public const MAX_IMPORT_ROWS = 500000;

    /** Nivel de severidad de las alertas de seguridad (escala 1-10). */
    private const SECURITY_ALERT_LEVEL = 9;

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
            if (filesize($filePath) > self::MAX_FILE_SIZE) {
                throw new ValidationException('El archivo excede el tamaño máximo de 128MB');
            }

            // Una sola pasada sobre el archivo: la inspección de seguridad
            // (contenido peligroso, validación por columna, límite de filas)
            // se hace inline dentro del loop de importación. Así no se lee el
            // CSV dos veces (leer 200k+ filas / 128MB duplicado era el cuello
            // de botella). Si algo no pasa, se lanza excepción y el rollback
            // de la transacción descarta los lotes ya insertados.

            [$stream, $dataOffset] = self::openStreamUtf8($filePath);
            $separator = self::detectSeparator($stream);
            fseek($stream, $dataOffset);

            $rawHeader = fgetcsv($stream, 10000, $separator);
            if (!$rawHeader || count($rawHeader) < 2) {
                throw new ValidationException('Formato de CSV inválido.');
            }

            $headerErrors = CsvContentInspector::validateHeaderColumns($rawHeader);
            if (!empty($headerErrors)) {
                throw new ValidationException(
                    "Las columnas del archivo no coinciden con el formato requerido:\n"
                    . implode("\n", $headerErrors)
                    . "\n\nColumnas esperadas, en este orden exacto: "
                    . implode(', ', CsvContentInspector::expectedHeaders())
                );
            }

            $header = array_map([CsvRowMapper::class, 'normalizeString'], $rawHeader);

            CsvImportProcessor::toggleIndexes($conn, disable: true);
            $conn->beginTransaction();

            $count = CsvImportProcessor::processAndInsertRows(
                $stream,
                $separator,
                $header,
                $fallbackYear,
                [$idPersona, $ip, $equipo],
                $filePath,
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
                CsvImportProcessor::toggleIndexes($conn, disable: false);
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

    public static function validateCSV(string $filePath): array
    {
        $result = ['success' => false, 'rows' => 0, 'errors' => []];

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $result['errors'][] = 'No se puede leer el archivo.';
            return $result;
        }

        // Inspección de contenido (seguridad) + validación por columna.
        $scan = self::safeScanCsvFile($filePath);

        if (!empty($scan['suspiciousRows'])) {
            $result['errors'][] = 'El archivo contiene contenido potencialmente peligroso '
                . '(etiquetas HTML, javascript:, onerror o fórmulas CSV) y fue bloqueado.';
        } elseif ($scan['overflow']) {
            $result['errors'][] = sprintf(
                'El archivo supera el límite de %d filas permitidas.',
                self::MAX_IMPORT_ROWS
            );
        } elseif (!empty($scan['validationErrors'])) {
            $result['errors'] = array_merge(
                ['El archivo tiene datos que no cumplen el esquema esperado:'],
                array_slice($scan['validationErrors'], 0, 25)
            );
        } else {
            $result['success'] = true;
            $result['rows'] = $scan['rows'];
        }

        return $result;
    }

    private static function safeScanCsvFile(string $filePath): array
    {
        try {
            return CsvContentInspector::scanCsvFile($filePath);
        } catch (\Throwable $e) {
            return ['rows' => 0, 'overflow' => false, 'suspiciousRows' => [], 'validationErrors' => [$e->getMessage()]];
        }
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
                fputcsv($fp, array_map(
                    static fn($v) => CsvContentInspector::escapeCsvCell($v === null ? '' : (string)$v),
                    $rec
                ));
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
        if (self::usesDatabaseHistory()) {
            $safeLimit = max(1, min(500, $limit));
            try {
                return EntityManagerProvider::get()->getConnection()->fetchAllAssociative(
                    "SELECT TOP ({$safeLimit})
                            CONVERT(VARCHAR(19), fecha, 120) AS [timestamp],
                            accion AS [action], detalle AS [details]
                     FROM Academico.HistorialAplicacion
                     ORDER BY id DESC"
                );
            } catch (\Throwable $e) {
                throw new SystemException('No se pudo leer el historial compartido.', 0, $e);
            }
        }

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
            if (self::usesDatabaseHistory()) {
                EntityManagerProvider::get()->getConnection()->insert('Academico.HistorialAplicacion', [
                    'accion' => mb_substr($action, 0, 100),
                    'detalle' => mb_substr($details, 0, 2000),
                    'fecha' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                    'nodo' => mb_substr(gethostname() ?: 'unknown', 0, 100),
                ]);
                return;
            }

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

    private static function usesDatabaseHistory(): bool
    {
        $value = $_ENV['HISTORY_STORAGE'] ?? (getenv('HISTORY_STORAGE') ?: 'file');
        $mode = strtolower(trim((string) $value));
        if (!in_array($mode, ['file', 'database'], true)) {
            throw new InvalidConfigurationException('HISTORY_STORAGE debe ser "file" o "database".');
        }
        return $mode === 'database';
    }

    /**
     * Registra una alerta de seguridad de nivel 9 para una importación con
     * contenido sospechoso. Persiste hash del archivo, usuario, IP, equipo,
     * nombre del archivo, conteo de filas y hallazgos.
     */
    public static function alertSuspiciousImport(
        string $filePath,
        int $idPersona,
        string $ip,
        string $equipo,
        int $rows,
        array $suspiciousRows
    ): void {
        $hash = '';
        $archivo = '';
        if (is_file($filePath)) {
            $hash    = hash_file('sha256', $filePath);
            $archivo = basename($filePath);
        }
        $hash = $hash !== false ? $hash : '';

        SecurityAlertService::log(self::SECURITY_ALERT_LEVEL, 'csv_import_sospechoso', [
            'hash'      => $hash,
            'usuario'   => $idPersona,
            'ip'        => $ip,
            'equipo'    => $equipo,
            'archivo'   => $archivo,
            'filas'     => $rows,
            'hallazgos' => array_slice($suspiciousRows, 0, 50),
        ]);
    }

    /**
     * Abre el archivo CSV como stream, detectando y normalizando su
     * codificación a UTF-8. Devuelve [stream, offset de datos].
     */
    public static function openStreamUtf8(string $filePath): array
    {
        $raw = self::openRawStream($filePath);

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

        return [self::convertStreamToUtf8($raw, $encoding, $hasBom), 0];
    }

    /**
     * Abre el archivo con reintentos. Lanza una excepción clara si no puede.
     */
    private static function openRawStream(string $filePath)
    {
        $maxRetries = 5;
        $retryDelayMs = 500;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $raw = @fopen($filePath, 'rb');
            if ($raw !== false) {
                return $raw;
            }

            error_log('[CsvService] fopen falló (intento ' . $attempt . '/' . $maxRetries . '): ' . $filePath);
            usleep($retryDelayMs * 1000);
        }

        $reason = self::describeOpenFailure($filePath);
        throw new ValidationException(
            'No se pudo abrir el archivo CSV: ' . $reason . ' Ruta: ' . $filePath
        );
    }

    /**
     * Convierte el stream crudo a UTF-8 en un stream temporal.
     */
    private static function convertStreamToUtf8($raw, string $encoding, bool $hasBom)
    {
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

        return $temp;
    }

    public static function detectSeparator($stream): string
    {
        $line = fgets($stream);
        return ($line !== false && strpos($line, ';') !== false) ? ';' : ',';
    }

    /**
     * Describe el motivo por el que no se pudo abrir un archivo, para dar un
     * mensaje de error accionable (ruta inexistente vs. sin permisos vs.
     * otro error de fopen).
     */
    private static function describeOpenFailure(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return 'El archivo no existe en la ruta indicada (posible problema de volumen compartido entre contenedores).';
        }
        if (!is_readable($filePath)) {
            return 'El archivo existe pero no es legible (permisos del contenedor).';
        }
        $last = error_get_last();
        return $last !== null ? ('Error de lectura: ' . $last['message']) : 'Error desconocido de lectura.';
    }
}
