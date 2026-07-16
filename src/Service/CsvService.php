<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\EntityManagerProvider;
use App\Entity\AcademicRecord;
use Throwable;

class CsvService
{
    public static function importCSV(string $filePath, int $fallbackYear): array
    {
        $result = ['success' => false, 'imported' => 0, 'errors' => []];
        $em = EntityManagerProvider::get();
        $conn = $em->getConnection();
        $stream = null;

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

            $separator = self::detectSeparator($stream);
            rewind($stream);

            $rawHeader = fgetcsv($stream, 10000, $separator);
            if (!$rawHeader || count($rawHeader) < 2) {
                throw new \RuntimeException('Formato de CSV inválido.');
            }
            $header = array_map([self::class, 'normalizeString'], $rawHeader);
            $schema = ConfigService::getColumnSchema();

            $conn->beginTransaction();
            $batchSize = 100;
            $count = 0;

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
                $em->persist($record);
                $count++;

                if (($count % $batchSize) === 0) {
                    $em->flush();
                    $em->clear();
                }
            }

            $em->flush();
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
        $header = fgetcsv($stream, 10000, ',');
        if (!$header || count($header) < 2) {
            fclose($stream);
            $result['errors'][] = 'Formato CSV inválido.';
            return $result;
        }
        $rows = 0;
        while (fgetcsv($stream, 10000, ',') !== false) {
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