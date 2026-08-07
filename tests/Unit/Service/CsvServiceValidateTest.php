<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CsvService;
use PHPUnit\Framework\TestCase;

/**
 * Tests para CsvService::validateCSV — la puerta de entrada del import.
 * Estos tests NO requieren base de datos: validateCSV solo lee y valida
 * el archivo.
 */
final class CsvServiceValidateTest extends TestCase
{
    /** @var string[] Rutas creadas durante el test, para limpiar en tearDown. */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    private function makeTempCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csvtest_') . '.csv';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    public function testReturnsErrorWhenFileDoesNotExist(): void
    {
        $result = CsvService::validateCSV('/ruta/inexistente/archivo.csv');
        self::assertFalse($result['success']);
        self::assertNotEmpty($result['errors']);
    }

    public function testRejectsCsvWithWrongHeaders(): void
    {
        $csv = "col1,col2,col3\nval1,val2,val3\n";
        $path = $this->makeTempCsv($csv);

        $result = CsvService::validateCSV($path);
        self::assertFalse($result['success']);
        self::assertNotEmpty($result['errors']);
    }

    public function testAcceptsCsvWithExpectedHeaders(): void
    {
        // Encabezados exactos exigidos por CsvService::EXPECTED_HEADERS
        // (definidos en el propio servicio; se replican textualmente aquí).
        $headers = [
            'Proceso', 'Curso', 'Grupo Objetivo', 'Modalidad',
            'Nro. de Horas', 'Fecha Inicio', 'Facha Fin',
            'Cedula', 'Nombre', 'Apellido', 'Email', 'Genero',
            'Tipo', 'Cargo', 'Provincia', 'Total', 'Aprueba', 'Año',
        ];
        $rowData = [
            'Proceso Juridico', 'Curso A', 'Grupo 1', 'Presencial',
            '40', '2024-01-10', '2024-02-10',
            '1234567890', 'Juan', 'Perez', 'juan@example.com', 'M',
            'Curso', 'Asistente', 'Pichincha', '95', 'SI', '2024',
        ];
        $csv = implode(',', $headers) . "\n" . implode(',', $rowData) . "\n";
        $path = $this->makeTempCsv($csv);

        $result = CsvService::validateCSV($path);
        self::assertTrue(
            $result['success'],
            'CSV con encabezados válidos debería pasar validación. Errores: '
                . implode('; ', $result['errors'])
        );
        self::assertSame(1, $result['rows']);
    }

    public function testRejectsCsvWithHtmlTag(): void
    {
        $csv = $this->csvWithCell('<script>alert(1)</script>');
        $result = CsvService::validateCSV($this->makeTempCsv($csv));
        self::assertFalse($result['success']);
        self::assertStringContainsString('peligroso', implode(' ', $result['errors']));
    }

    public function testRejectsCsvWithJavascriptUri(): void
    {
        $csv = $this->csvWithCell('javascript:alert(1)');
        $result = CsvService::validateCSV($this->makeTempCsv($csv));
        self::assertFalse($result['success']);
        self::assertStringContainsString('peligroso', implode(' ', $result['errors']));
    }

    public function testRejectsCsvWithOnErrorHandler(): void
    {
        $csv = $this->csvWithCell('<img src=x onerror=alert(1)>');
        $result = CsvService::validateCSV($this->makeTempCsv($csv));
        self::assertFalse($result['success']);
        self::assertStringContainsString('peligroso', implode(' ', $result['errors']));
    }

    public function testRejectsCsvWithCsvFormula(): void
    {
        $csv = $this->csvWithCell('=HYPERLINK("http://evil.com","click")');
        $result = CsvService::validateCSV($this->makeTempCsv($csv));
        self::assertFalse($result['success']);
        self::assertStringContainsString('peligroso', implode(' ', $result['errors']));
    }

    public function testRejectsCsvWithNonNumericTotal(): void
    {
        $csv = $this->csvWithCell('noventa');
        $result = CsvService::validateCSV($this->makeTempCsv($csv));
        self::assertFalse($result['success']);
        self::assertStringContainsString('numérico', implode(' ', $result['errors']));
    }

    private function csvWithCell(string $value): string
    {
        $headers = [
            'Proceso', 'Curso', 'Grupo Objetivo', 'Modalidad',
            'Nro. de Horas', 'Fecha Inicio', 'Facha Fin',
            'Cedula', 'Nombre', 'Apellido', 'Email', 'Genero',
            'Tipo', 'Cargo', 'Provincia', 'Total', 'Aprueba', 'Año',
        ];
        // Reemplaza la celda 'Total' (columna 16) con el valor sospechoso.
        $row = [
            'Proceso Juridico', 'Curso A', 'Grupo 1', 'Presencial',
            '40', '2024-01-10', '2024-02-10',
            '1234567890', 'Juan', 'Perez', 'juan@example.com', 'M',
            'Curso', 'Asistente', 'Pichincha', '95', 'SI', '2024',
        ];
        $row[15] = $value;
        return implode(',', $headers) . "\n" . implode(',', $row) . "\n";
    }

    public function testRejectsMalformedCsvWithSingleColumn(): void
    {
        $csv = "solo_una_columna\nvalor\n";
        $path = $this->makeTempCsv($csv);

        $result = CsvService::validateCSV($path);
        self::assertFalse($result['success']);
    }
}