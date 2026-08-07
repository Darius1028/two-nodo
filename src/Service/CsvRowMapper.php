<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Mapea filas crudas del CSV hacia los parámetros de inserción de
 * AcademicRecord. Extraído de CsvService para reducir su tamaño.
 */
final class CsvRowMapper
{
    public static function normalizeString(mixed $str): string
    {
        $str = mb_strtolower(trim((string)$str), 'UTF-8');
        return strtr($str, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    public static function combineRowData(array $data, array $header): array
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

    public static function findValue(array $normalizedRow, array $schema, string $key, bool $isNumeric = false): mixed
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

    /**
     * Construye los parámetros de inserción a partir de una fila del CSV.
     */
    public static function buildInsertParams(array $row, array $schema, int $fallbackYear): array
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
}
