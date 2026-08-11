<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;

/**
 * Inspección de contenido de archivos CSV: validación de encabezados,
 * reglas de contenido (XSS almacenado / inyección de fórmulas CSV) y
 * validación por columna. Extraído de CsvService para reducir su tamaño.
 */
final class CsvContentInspector
{
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
     * Reglas de validación por columna (campo => reglas). La clave coincide
     * con los campos de AcademicRecord / buildInsertParams y con las
     * longitudes reales de las columnas en SQL Server.
     *
     * @var array<string, array{maxLength?: int, numeric?: bool}>
     */
    private const COLUMN_RULES = [
        'cedula'         => ['maxLength' => 20],
        'nombre'         => ['maxLength' => 255],
        'apellido'       => ['maxLength' => 255],
        'email'          => ['maxLength' => 255],
        'curso'          => ['maxLength' => 255],
        'materia'        => ['maxLength' => 255],
        'total'          => ['numeric' => true, 'maxLength' => 5],
        'periodo'        => ['maxLength' => 50],
        'anio'           => ['numeric' => true, 'maxLength' => 4],
        'proceso'        => ['maxLength' => 100],
        'grupo_objetivo' => ['maxLength' => 200],
        'modalidad'      => ['maxLength' => 50],
        'nro_horas'      => ['numeric' => true, 'maxLength' => 5],
        'genero'         => ['maxLength' => 20],
        'tipo'           => ['maxLength' => 100],
        'cargo'          => ['maxLength' => 150],
        'provincia'      => ['maxLength' => 100],
        'fecha_inicio'   => ['maxLength' => 20],
        'fecha_fin'      => ['maxLength' => 20],
        'aprueba'        => ['maxLength' => 20],
    ];

    /**
     * Reglas de contenido: patrones considerados contenido peligroso (XSS
     * almacenado / inyección de fórmulas CSV). Un archivo con cualquiera de
     * estos se bloquea y dispara una alerta de seguridad de nivel 9 con el
     * hash del archivo, el usuario y el conteo de filas.
     *
     * Nota: el guión (`-`) solo se marca cuando inicia una expresión real
     * (guión seguido de dígito/letra/símbolo), no cuando es el marcador
     * legítimo "sin calificación" (`-` o ` - `) usado en los CSV de origen.
     *
     * @var array<string, string>
     */
    private const DANGEROUS_PATTERNS = [
        'etiqueta HTML'           => '/<[a-zA-Z!\/][^>]*>/',
        'etiqueta <script>'       => '/<\s*script/i',
        'esquema javascript:'     => '/\bjavascript\s*:/i',
        'esquema vbscript:'       => '/\bvbscript\s*:/i',
        'handler on*='            => '/\bon(?:error|load|click|mouseover|mouseout|focus|blur|change|submit|keydown|keyup|dblclick|input|animationstart|transitionend)\s*=/i',
        'fórmula CSV ='           => '/^[ \t]*=/',
        'fórmula CSV +'           => '/^[ \t]*\+/',
        'fórmula CSV -'           => '/^[ \t]*-(?=[0-9A-Za-z@=+])/',
        'fórmula CSV @'           => '/^[ \t]*@/',
        'carácter de control'     => '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
    ];

    private function __construct()
    {
    }

    /**
     * Lista canónica de encabezados esperados (para mensajes al usuario).
     *
     * @return string[]
     */
    public static function expectedHeaders(): array
    {
        return self::EXPECTED_HEADERS;
    }

    /**
     * Compara los encabezados reales del CSV contra EXPECTED_HEADERS,
     * posición por posición. Devuelve un array vacío si coincide, o la
     * lista de diferencias encontradas (para mostrárselas al usuario).
     *
     * @param array<int, mixed> $rawHeader
     * @return string[]
     */
    public static function validateHeaderColumns(array $rawHeader): array
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
            $v = CsvRowMapper::normalizeString($v);
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

    /**
     * Busca contenido peligroso en las celdas de una fila. Devuelve hallazgos
     * (vacío si la fila es segura).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findSuspiciousCells(array $row, int $rowNumber): array
    {
        $suspicious = [];
        foreach ($row as $column => $cell) {
            $cellStr = is_scalar($cell) ? (string)$cell : '';
            foreach (self::DANGEROUS_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $cellStr) === 1) {
                    $suspicious[] = [
                        'row'     => $rowNumber,
                        'column'  => (string)$column,
                        'pattern' => $label,
                        'sample'  => self::truncateSample($cellStr),
                    ];
                    break;
                }
            }
        }
        return $suspicious;
    }

    /**
     * Valida tipo, longitud y caracteres de cada celda de una fila contra
     * COLUMN_RULES. Devuelve mensajes de error por columna (vacíos si OK).
     *
     * @return string[]
     */
    public static function validateRowCells(array $row, array $schema, int $rowNumber): array
    {
        $errors = [];

        foreach (self::COLUMN_RULES as $key => $rules) {
            // Valor crudo sin coerción numérica, para validar el tipo real.
            $value = CsvRowMapper::findValue($row, $schema, $key, false);
            if ($value === null || trim((string)$value) === '') {
                continue;
            }
            $str = trim((string)$value);

            if (!empty($rules['numeric'])) {
                // Marcador legítimo "sin dato" (uno o más guiones) en columnas
                // numéricas: el import lo traduce a NULL.
                if (preg_match('/^-{1,3}$/', $str) === 1) {
                    continue;
                }
                if (!is_numeric(str_replace(',', '.', $str))) {
                    $errors[] = sprintf(
                        'Fila %d, columna "%s": se esperaba un valor numérico, se recibió "%s".',
                        $rowNumber,
                        $key,
                        self::truncateSample($str)
                    );
                    continue;
                }
            }

            if (!empty($rules['maxLength']) && mb_strlen($str) > $rules['maxLength']) {
                $errors[] = sprintf(
                    'Fila %d, columna "%s": supera la longitud máxima de %d caracteres (%d recibidos).',
                    $rowNumber,
                    $key,
                    $rules['maxLength'],
                    mb_strlen($str)
                );
            }
        }

        return $errors;
    }

    /**
     * Escanea el archivo CSV completo aplicando reglas de contenido y
     * validación por columna. No lanza excepciones: devuelve la estructura
     * completa para que el llamador decida.
     *
     * @return array{rows: int, overflow: bool, suspiciousRows: array<int, array<string, mixed>>, validationErrors: string[]}
     */
    public static function scanCsvFile(string $filePath): array
    {
        $scan = ['rows' => 0, 'overflow' => false, 'suspiciousRows' => [], 'validationErrors' => []];

        [$stream, $dataOffset] = CsvService::openStreamUtf8($filePath);
        $separator = CsvService::detectSeparator($stream);
        fseek($stream, $dataOffset);

        $rawHeader = fgetcsv($stream, 10000, $separator);
        if (!$rawHeader || count($rawHeader) < 2) {
            fclose($stream);
            $scan['validationErrors'][] = 'Formato CSV inválido (menos de dos columnas).';
            return $scan;
        }

        $headerErrors = self::validateHeaderColumns($rawHeader);
        if (!empty($headerErrors)) {
            fclose($stream);
            $scan['validationErrors'] = array_merge(
                ['Las columnas del archivo no coinciden con el formato requerido:'],
                $headerErrors,
                ['Columnas esperadas, en este orden exacto: ' . implode(', ', self::EXPECTED_HEADERS)]
            );
            return $scan;
        }

        $header = array_map([CsvRowMapper::class, 'normalizeString'], $rawHeader);
        $schema = ConfigService::getColumnSchema();

        $rows = 0;
        while (($data = fgetcsv($stream, 10000, $separator)) !== false) {
            $rows++;
            if ($rows > CsvService::MAX_IMPORT_ROWS) {
                $scan['overflow'] = true;
                break;
            }
            $row = CsvRowMapper::combineRowData($data, $header);

            // 1) Contenido peligroso en las celdas (XSS / fórmulas CSV)
            $scan['suspiciousRows'] = array_merge(
                $scan['suspiciousRows'],
                self::findSuspiciousCells($row, $rows)
            );

            // 2) Validación por columna (tipo, longitud, caracteres)
            $scan['validationErrors'] = array_merge(
                $scan['validationErrors'],
                self::validateRowCells($row, $schema, $rows)
            );
        }

        fclose($stream);

        $scan['rows'] = $rows;
        return $scan;
    }

    public static function truncateSample(string $value, int $max = 40): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
        return mb_strlen($clean) > $max ? mb_substr($clean, 0, $max) . '…' : $clean;
    }

    /**
     * Escapa una celda para el contexto de salida CSV (exportCSV). Neutraliza
     * la inyección de fórmulas (OWASP CSV Injection): las celdas que arrancan
     * con = + - @ se prefijan con un apóstrofe para que Excel/LibreOffice no
     * las interpreten como fórmula al abrir el archivo.
     */
    public static function escapeCsvCell(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }
}
